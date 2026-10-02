<?php

/**
 * This file is part of the MediaWiki extension VisualData.
 *
 * VisualData is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * VisualData is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with VisualData.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2024-2026, https://wikisphere.org
 */

namespace MediaWiki\Extension\VisualData;

use MediaWiki\Extension\VisualData\Aliases\Title as TitleClass;
use MediaWiki\Extension\VisualData\Utils\DateParser;
use MediaWiki\MediaWikiServices;

class QueryProcessor {

	/** @var User */
	private $user = [];

	/** @var array */
	private $schema = [];

	/** @var array|string|int */
	private $result;

	/** @var array */
	private $resultsList = [];

	/** @var array */
	private $conditionProperties = [];

	/** @var array */
	private $conditionSubjects = [];

	/** @var array */
	private $printouts = [];

	/** @var array */
	private $printoutsOriginal = [];

	/** @var array */
	private $params = [];

	/** @var dbr */
	private $dbr;

	/** @var int */
	private $count;

	/** @var bool */
	private $treeFormat;

	/** @var array */
	private $AndConditions = [];

	/** @var int */
	private $conditionId = null;

	/** @var string */
	private $query;

	/** @var DatabaseManager */
	private $databaseManager;

	/** @var array */
	private $errors = [];

	/** @var array */
	private $mapKeyToPrintout;

	/** @var array */
	private $mapPathNoIndexTable = [];

	/** @var array */
	private $mapPrintoutParentSchemas = [];

	/** @var array */
	private $conditionsUseHaving = [];

	/** @var int */
	private $schemaId = [];

	/** @var bool */
	private $debug;

	/** @var array */
	private $specialPrefixes = [
		'Creation date',
		'CreationDate',
		'Modification date',
		'ModificationDate'
	];

	/**
	 * @param User $user
	 * @param array $schema
	 * @param string $query
	 * @param array $printouts
	 * @param array $params
	 */
	public function __construct( $user, $schema, $query, $printouts, $params ) {
		$defaultParameters = [
			'schema' => [ '', 'string' ],
			'limit' => [ 100, 'integer' ],
			'offset' => [ 0, 'integer' ],
			'order' => [ '', 'string' ],
			'pagetitle' => [ 'pagetitle', 'string' ],
			'hierarchical-conditions' => [ true, 'bool' ],
			'printouts-from-conditions' => [ false, 'bool' ],
			'categories' => [ false, 'bool' ],
			'count-printout' => [ false, 'bool' ],
			'count-printout-min' => [ 1, 'integer' ],
			'count-categories' => [ false, 'bool' ],
			'debug' => [ false, 'bool' ],
			'secondary-printouts' => [ false, 'bool' ]
		];
		$params = \VisualData::applyDefaultParams( $defaultParameters, $params );

		$this->debug = $params['debug'];
		$this->user = $user;
		$this->databaseManager = new DatabaseManager();
		$this->schema = $schema;
		$this->query = $query;
		$this->printouts = $printouts;
		$this->printoutsOriginal = $this->printouts;
		$this->params = $params;
		$this->dbr = \VisualData::getDB( DB_REPLICA );

		$this->parseQuery();
		$this->prepareQuery();
	}

	/**
	 * @return int
	 */
	public function getCount() {
		$this->count = true;
		$this->treeFormat = false;
		$categories = $this->params['categories'];
		$this->params['categories'] = false;
		$this->performQuery();
		$this->params['categories'] = $categories;
		if ( !count( $this->errors ) ) {
			return (int)$this->result;
		}
		return -1;
	}

	/**
	 * @return array
	 */
	public function getErrors() {
		return $this->errors;
	}

	/**
	 * @return array
	 */
	public function getValidPrintouts() {
		return $this->printouts;
	}

	/**
	 * @return array
	 */
	public function isPrintCondition() {
		return !empty( $this->conditionId );
	}

	/**
	 * @return int
	 */
	public function getCountTree() {
		$this->count = true;
		$this->treeFormat = true;
		$this->performQuery();
		if ( !count( $this->errors ) ) {
			return (int)$this->result;
		}
		return -1;
	}

	/**
	 * @return array|int
	 */
	public function getResults() {
		$this->count = false;
		$this->treeFormat = false;
		$this->performQuery();
		if ( !count( $this->errors ) ) {
			return $this->result;
		}
		return [];
	}

	/**
	 * @return array
	 */
	public function getResultsTree() {
		$this->count = false;
		$this->treeFormat = true;
		$this->performQuery();
		if ( !count( $this->errors ) ) {
			return $this->result;
		}
		return [];
	}

	/**
	 * @return array
	 */
	public function getQueryData() {
		return [
			'query' => $this->query,
			'printouts' => $this->printouts,
			'params' => $this->params
		];
	}

	private function parseQuery() {
		// pageid
		if ( is_numeric( $this->query ) ) {
			$this->conditionId = (int)trim( $this->query );
			return;
		}

		// e.g. [[new_property::+]][[~*ab*||new_property::~*ab*||new_property_2::~*ab*]]
		preg_replace_callback( '/\[\[(.+?)\]\]/',
			function ( $matches ) {
				$arr = explode( '||', $matches[1] );
				$rel = [];
				$orConditionsSubjects = [];
				$orConditions = [];

				foreach ( $arr as $key => $value ) {
					if ( strpos( $value, '::' ) === false ) {
						$value = trim( $value );
						// $orConditionsSubjects[] = $value;
						$orConditions['page_title'][] = $value;
						$this->conditionSubjects[] = $value;

					} else {
						[ $prop, $value ] = explode( '::', $value );
						$orConditions[$prop][] = trim( $value );
						$this->conditionProperties[] = trim( $prop );
					}
				}
				if ( count( $orConditions ) ) {
					$this->AndConditions[] = $orConditions;
				}
			}, $this->query );

		// check if is a title as in VisualData -> parserFunctionPrint
		// @FIXME check only if the origin function is parserFunctionQuery
		if ( empty( $this->AndConditions ) ) {
			$title_ = TitleClass::newFromText( $this->query );
			// allow also unknown titles, in case is used within a parser function
			// && $title_->isKnown()
			if ( $title_ ) {
				$this->conditionId = $title_->getArticleID();
			}
		}
	}

	/**
	 * @param string $table_id
	 * @return string
	 */
	private function tableNameFromId( $table_id ) {
		switch ( $table_id ) {
			case 1:
				return 'text';
			case 2:
				return 'textarea';
			case 3:
				return 'date';
			case 4:
				return 'datetime';
			case 5:
				return 'time';
			case 6:
				return 'integer';
			case 7:
				return 'numeric';
			case 8:
				return 'boolean';
		}
	}

	/**
	 * @param string $prefix
	 * @return string
	 */
	private function getSpecialPrefixOrderFunc( $prefix ) {
		switch ( $prefix ) {
			case 'ModificationDate':
			case 'Modification date':
				return 'max';
			case 'CreationDate':
			case 'Creation date':
				return 'min';
		}
		return 'min';
	}

	/**
	 * @param int $firstKey
	 * @param array $orderBy
	 * @param array &$fields
	 * @param array &$tables
	 * @param array &$joins
	 * @return array
	 */
	private function getOptions( $firstKey, $orderBy, &$fields, &$tables, &$joins ) {
		if ( $this->count || $this->params['count-printout']
			|| $this->params['count-categories']
		) {
			return [];
		}

		$options = [];
		$optionsMap = [
			'order' => 'ORDER BY',
			'limit' => 'LIMIT',
			'offset' => 'OFFSET',
		];

		$thisClass = $this;
		$castField = static function ( $printout, $index ) use ( $thisClass ) {
			$tablename = $thisClass->mapPathNoIndexTable[$printout];
			$cast = null;
			switch ( $tablename ) {
				case 'text':
				case 'textarea':
					break;
				case 'date':
					$cast = 'DATE';
					break;
				case 'datetime':
					$cast = 'DATETIME';
					break;
				case 'time':
					$cast = 'TIME';
					break;
				case 'integer':
					$cast = 'SIGNED';
					break;
				case 'numeric':
					$cast = 'DOUBLE';
					break;
				case 'boolean':
					$cast = 'UNSIGNED';
					break;
			}

			if ( !$cast ) {
				return "v$index";
			}

			return "CAST(v$index as $cast)";
		};

		$specialPrefix = false;
		// $options = ['GROUP BY' => 'page_id'];
		foreach ( $optionsMap as $key => $value ) {
			if ( !empty( $this->params[$key] ) ) {
				switch ( $key ) {
					case 'order':
						$arr = [];
						foreach ( $orderBy as $printout_ => $sort_ ) {
							$index = array_search( $printout_, $this->mapKeyToPrintout );

							if ( $index !== false ) {
								// *** this is may be superflous
								// $arr[] = $castField( $printout_, $index ) . " $sort_";
								$arr[] = "v$index $sort_";

								// used only for sorting, not concatenated
								// to preserve type
								if ( $this->treeFormat ) {
									$fields["v$index"] = "t$index.value";
								}

							} elseif ( in_array( $printout_, ResultPrinter::$titleAliases ) ) {
								$arr[] = "page_title $sort_";

							} elseif ( in_array( $printout_, $this->specialPrefixes ) ) {
								$arr[] = "rev_sort.timestamp $sort_";
								$specialPrefix = $printout_;
							}
						}
						if ( count( $arr ) ) {
							$options[$value] = implode( ', ', $arr );
						}
						break;
					case 'limit':
					case 'offset':
						$val = $this->params[$key];
						// *** this shouldn't be anymore necessary
						if ( preg_match( '/^\s*\d+\s*$/', $val ) ) {
							$options[$value] = (int)$this->params[$key];
						}
						break;
				}
			}
		}

		if ( $specialPrefix ) {
			$orderFunc = $this->getSpecialPrefixOrderFunc( $specialPrefix );

			$tables_ = [ 'revision' ];
			$fields_  = [ 'rev_page', 'timestamp' => "$orderFunc(rev_timestamp)" ];
			$conds_ = [];
			$options_ = [ 'GROUP BY' => 'rev_page' ];
			$subquery = $this->dbr->buildSelectSubquery(
				$tables_,
				$fields_,
				$conds_,
				__METHOD__,
				$options_,
			);

			// $fields[] = 'rev_sort.timestamp';
			$tables['rev_sort'] = $subquery;
			$joins['rev_sort'] = [ 'JOIN', [
				"rev_sort.rev_page = t$firstKey.page_id",
			] ];
		}

		return $options;
	}

	/**
	 * @param string $dataType
	 * @param string|int|float|bool &$val
	 */
	private function castValAndQuote( $dataType, &$val ) {
		switch ( $dataType ) {
			case 'integer':
				$val = filter_var( $val, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE );
				settype( $val, 'integer' );
				break;
			case 'numeric':
				$val = filter_var( $val, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );
				settype( $val, 'float' );
				break;
			case 'date':
			case 'datetime':
			case 'time':
				switch ( $dataType ) {
					case 'date':
						$format = 'Y-m-d';
						break;
					case 'datetime':
						$format = 'Y-m-d H:i:s';
						break;
					case 'time':
						$format = 'H:i:s';
						break;
				}
				$dateParser = new DateParser( $val );
				$timestamp = $dateParser->parse();
				$val = date( $format, $timestamp );
				$val = $this->dbr->addQuotes( $val );
				break;
			case 'boolean':
				$val = filter_var( $val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				settype( $val, 'boolean' );
				$val = $val ? 'TRUE' : 'FALSE';
				break;
			case 'text':
			case 'textarea':
			default:
				settype( $val, 'string' );
				$val = $this->dbr->addQuotes( $val );
		}
	}

	/**
	 * @param string $tableName
	 * @param mixed $value
	 * @return bool
	 */
	private function isValidData( $tableName, $value ) {
		$typeOfValue = SchemaProcessor::getType( $value );

		// ignore conditions for non-matching datatypes
		// a use-case of this is datatables search, which
		// is performed on all printouts
		switch ( $tableName ) {
			case 'text':
				// an integer can be a string for instance as a result of
				// dechex, or "1984" (book title)
				if ( $typeOfValue !== 'string' && $typeOfValue !== 'number' ) {
					return false;
				}
				break;

			case 'textarea':
				if ( $typeOfValue !== 'string' ) {
					return false;
				}
				break;

			case 'integer':
				// phpcs:ignore Generic.ControlStructures.DisallowYodaConditions.Found
				if ( null === filter_var( $value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE ) ) {
					return false;
				}
				break;

			case 'numeric':
				// phpcs:ignore Generic.ControlStructures.DisallowYodaConditions.Found
				if ( null === filter_var( $value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE ) ) {
					return false;
				}
				break;

			case 'boolean':
				// phpcs:ignore Generic.ControlStructures.DisallowYodaConditions.Found
				if ( null === filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ) {
					return false;
				}
				break;

			// time, date and datetime support LIKE and
			// are stored by mysql as string
			case 'time':
			case 'date':
			case 'datetime':
				if ( $typeOfValue !== 'string' && $typeOfValue !== 'integer' ) {
					return false;
				}
				break;

			default:
				if ( $tablename !== $typeOfValue ) {
					return false;
				}
		}

		return true;
	}

	/**
	 * @param string $exp
	 * @param string $field
	 * @param string|null $tableName string
	 * @param function|null $callbackValue null
	 * @return string|false
	 */
	private function parseCondition( $exp, $field, $tableName = 'text', $callbackValue = null ) {
		// use $this->dbr->buildLike( $prefix, $this->dbr->anyString() )
		// if $value contains ~
		$likeBefore = false;
		$likeAfter = false;
		$value = $exp;

		preg_match( '/^(!)?(~)?(.+?)(~)?$/', $exp, $match );

		if ( !empty( $match ) ) {
			$value = $match[3];

			if ( !empty( $match[2] ) ) {
				$likeBefore = true;
			}
			if ( !empty( $match[4] ) ) {
				$likeAfter = true;
			}
		}

		$thisClass = $this;
		$getCastValueAndQuote = static function ( $value ) use ( $thisClass, $tableName ) {
			$thisClass->castValAndQuote( $tableName, $value );
			return $value;
		};

		if ( !$likeBefore && !$likeAfter ) {
			if ( $value === '+' ) {
				return "$field IS NOT NULL";
			} elseif ( $value === '-' ) {
				return "$field IS NULL";
			}

			if ( in_array( $tableName, [ 'integer', 'numeric', 'date', 'datetime', 'time' ] ) ) {
				// https://www.semantic-mediawiki.org/wiki/Help:Search_operators#User_manual
				// @see https://phabricator.wikimedia.org/T387008
				$regex = '/^(=|>=|<=|>|<|!=|!)\s*+(.++)$/';

			} else {
				$regex = '/^(!=|!)\s*+(.++)$/';
			}

			preg_match( $regex, $exp, $match_ );

			if ( !empty( $match_ ) ) {
				$match_[2] = trim( $match_[2] );

				if ( $callbackValue ) {
					$match_[2] = $callbackValue( $match_[2] );
				}

				if ( !$this->isValidData( $tableName, $match_[2] ) ) {
					return false;
				}

				switch ( $match_[1] ) {
					case '!':
						$operator = '!=';
						break;
					default:
						$operator = $match_[1];
				}

				return "$field {$operator} " . $getCastValueAndQuote( $match_[2] );
			}

			if ( !$this->isValidData( $tableName, $value ) ) {
				return false;
			}

			return "$field = " . $getCastValueAndQuote( $value );
		}

		if ( !$this->isValidData( $tableName, $value ) ) {
			return false;
		}

		$any = $this->dbr->anyString();
		if ( $likeBefore && !$likeAfter ) {
			$quotedVal = $this->dbr->buildLike( $any, $value );
		} elseif ( !$likeBefore && $likeAfter ) {
			$quotedVal = $this->dbr->buildLike( $value, $any );
		} elseif ( $likeBefore && $likeAfter ) {
			$quotedVal = $this->dbr->buildLike( $any, $value, $any );
		} else {
			$quotedVal = $getCastValueAndQuote( $value );
		}
		$not = ( empty( $match[1] ) ) ? '' : ' NOT';

		return "{$field}{$not}{$quotedVal}";
	}

	/**
	 * @param string $str
	 * @return bool|array
	 */
	private function parseSpecialPrefix( $str ) {
		// only escape the following https://www.php.net/manual/en/regexp.reference.meta.php
		// @see https://phabricator.wikimedia.org/T387008
		$regex = '/^\s*+(.+?)\s*+(=|>=|<=|>|<|!=|!)\s*+(.++)$/';
		preg_match( $regex, $str, $match_ );

		if ( empty( $match_ ) ) {
			return [ null, null, null ];
		}

		if ( !in_array( $match_[1], $this->specialPrefixes ) ) {
			return [ null, null, null ];
		}

		$value = trim( $match_[3] );

		switch ( $match_[2] ) {
			case '!':
				$operator = '!=';
				break;
			default:
				$operator = $match_[2];
		}

		// prefix, value, operator
		return [ $match_[1], $value, $operator ];
	}

	/**
	 * @param int $firstKey
	 * @param string $value
	 * @param array &$orConds
	 * @param array &$tables
	 * @param array &$joins
	 * @param array &$fields
	 * @param array &$categories
	 */
	private function processTitle( $firstKey, $value, &$orConds, &$tables, &$joins, &$fields, &$categories ) {
		// match "Creation date"
		[ $specialPrefix, $prefixValue, $prefixOperator ] = $this->parseSpecialPrefix( $value );
		if ( $specialPrefix !== null ) {
			$orderFunc = $this->getSpecialPrefixOrderFunc( $specialPrefix );
			$time_ = strtotime( $prefixValue );
			// MW_TS
			// $value = date( 'YmdHis', $time );
			$timestamp_ = $this->dbr->timestamp( $time_ );

			$tables_ = [ 'revision' ];
			$fields_  = [ 'rev_page', 'timestamp' => "$orderFunc(rev_timestamp)" ];
			// *** do not set a condition here, or take into account
			// orderFunc and the operator
			// $conds_ = [ "rev_timestamp $prefixOperator $timestamp_" ];
			$conds_ = [];
			$options_ = [ 'GROUP BY' => 'rev_page' ];
			$subquery = $this->dbr->buildSelectSubquery(
				$tables_,
				$fields_,
				$conds_,
				__METHOD__,
				$options_,
			);

			$tables['rev_alias'] = $subquery;
			$joins['rev_alias'] = [ 'JOIN', [
				"rev_alias.rev_page = t$firstKey.page_id",
				"rev_alias.timestamp $prefixOperator $timestamp_",
			] ];
			return;
		}

		$title = TitleClass::newFromText( $value );
		if ( $title &&
			( $title->isKnown() || $title->getNamespace() === NS_CATEGORY )
		) {
			if ( $title->getNamespace() !== NS_CATEGORY ) {
				$orConds[] = "t$firstKey.page_id = " . $title->getArticleID();

			} else {
				$categories[] = $title;
			}

		} else {
			// we got something in this form A:A/~
			// $tables['page_alias'] = 'page';
			// 'USE INDEX' => ( version_compare( MW_VERSION, '1.36', '<' ) ? 'name_title' : 'page_name_title' ),
			// $joins['page_alias'] = [ 'JOIN', [ 'page_alias.page_id = t0.page_id' ] ];
			// @FIXME replace underscore inside parseCondition
			$value = str_replace( ' ', '_', $value );

			// check if is a registered namespace
			$nsIndex = \VisualData::getRegisteredNamespace( $value );
			$conds_ = [];
			if ( $nsIndex !== NS_MAIN ) {
				$conds_[] = "page_alias.page_namespace = $nsIndex";
			}
			$conds_[] = $this->parseCondition( $value, $this->fieldCaseInsensitive( 'page_alias.page_title' ) );
			$orConds[] = $this->dbr->makeList( $conds_, LIST_AND );
		}
	}

	/**
	 * @param string $fullColumnName
	 * @return string
	 */
	private function fieldCaseInsensitive( $fullColumnName ) {
		// return $fullColumnName;
		return "CONVERT($fullColumnName USING utf8mb4) COLLATE utf8mb4_general_ci";
	}

	/**
	 * @param int $firstKey
	 * @param array $mapConds
	 * @param array &$conds
	 * @param array &$tables
	 * @param array &$joins
	 * @param array &$fields
	 * @param array &$having
	 */
	private function getConditions( $firstKey, $mapConds, &$conds, &$tables, &$joins, &$fields, &$having ) {
		$isNewSchema = version_compare( MW_VERSION, '1.45', '>=' );

		foreach ( $this->AndConditions as $i => $value ) {
			// @ATTENTION !! with treeFormat the match in a
			// given set of OR conditions, must be performed after GROUP_CONCAT,
			// using HAVING (provided that are all LIKES and there is
			// at list one multiple item)
			// with count we keep the standard condition
			// since the number of items shouldn't be affected
			$orConds = [];
			$categories = [];
			$havingConds = [];
			$useHaving = in_array( $i, $this->conditionsUseHaving );

			foreach ( $value as $printout => $values ) {
				if ( array_key_exists( $printout, $mapConds ) ) {
					if ( $useHaving ) {
						$field = "c{$mapConds[$printout]['key']}";
					} else {
						$field = "t{$mapConds[$printout]['key']}.value";
					}
					$tablename = $mapConds[$printout]['tablename'];

					// OR conditions same property e.g. [[prop a::a||b]]
					foreach ( $values as $v ) {
						$condStr = $this->parseCondition( $v, $field, $tablename );
						if ( $condStr !== false ) {
							if ( $useHaving ) {
								$havingConds[] = $condStr;
							} else {
								$orConds[] = $condStr;
							}
						}
					}
				} elseif ( $printout === 'page_title' ) {
					foreach ( $values as $v ) {
						$this->processTitle( $firstKey, $v, $orConds, $tables, $joins, $fields, $categories );
					}
				}
			}

			if ( count( $categories ) ) {
				$catAlias = "categorylinks_$i";
				$linkAlias = "linktarget_$i";

				$categoryConds = [];
				foreach ( $categories as $title_ ) {
					$categoryField = $isNewSchema
						? $this->fieldCaseInsensitive( "$linkAlias.lt_title" )
						: $this->fieldCaseInsensitive( "$catAlias.cl_to" );

					$categoryConds[] = $this->parseCondition(
						$title_->getDbKey(),
						$categoryField
					);
				}

				$tables[$catAlias] = 'categorylinks';
				if ( $isNewSchema ) {
					$tables[$linkAlias] = 'linktarget';
				}

				$joins[$catAlias] = [
					'LEFT JOIN',
					"$catAlias.cl_from = t0.page_id"
				];

				if ( $isNewSchema ) {
					$joins[$linkAlias] = [
						'LEFT JOIN',
						"$linkAlias.lt_id = $catAlias.cl_target_id"
					];

					if ( $useHaving ) {
						$havingConds[] = $this->dbr->makeList( $categoryConds, LIST_OR );
					} else {
						$orConds[] = $this->dbr->makeList( $categoryConds, LIST_OR );
					}

				} else {
					$joins[$catAlias] = [
						'LEFT JOIN',
						$this->dbr->makeList( [
							"$catAlias.cl_from = t0.page_id",
							$this->dbr->makeList( $categoryConds, LIST_OR )
						], LIST_AND )
					];
				}

				if ( $useHaving && !$isNewSchema ) {
					$havingConds[] = "t$firstKey.page_id = $catAlias.cl_from";
					$fields[]      = "$catAlias.cl_from";

				} elseif ( !$useHaving && !$isNewSchema ) {
					$orConds[] = "t$firstKey.page_id = $catAlias.cl_from";
				}
			}

/*
			if ( count( $categories ) ) {
				$categoryConds = [];
				foreach ( $categories as $title_ ) {
					// or use the initial string $v passed to processTitle
					$categoryConds[] = $this->parseCondition( $title_->getDbKey(), $this->fieldCaseInsensitive( "categorylinks_$i.cl_to" ) );
				}

				$tables["categorylinks_$i"] = 'categorylinks';

				$joins["categorylinks_$i"] = [ 'LEFT JOIN',
					$this->dbr->makeList( [
						"categorylinks_$i.cl_from = t0.page_id",
						$this->dbr->makeList( $categoryConds, LIST_OR )
					], LIST_AND ) ];

				if ( $useHaving ) {
					$havingConds[] = "t$firstKey.page_id = categorylinks_$i.cl_from";
					$fields[] = "categorylinks_$i.cl_from";

				} else {
					$orConds[] = "t$firstKey.page_id = categorylinks_$i.cl_from";
				}
			}
*/
			if ( count( $orConds ) ) {
				$conds[] = $this->dbr->makeList( $orConds, LIST_OR );
			}

			if ( count( $havingConds ) ) {
				$having[] = $this->dbr->makeList( $havingConds, LIST_OR );
			}
		}

/*
		if ( $this->params['categories'] && !$this->params['count-printout'] ) {
			if ( !$this->params['count-categories'] ) {
				$fields['categories'] = "GROUP_CONCAT(categorylinks_t$firstKey.cl_to SEPARATOR 0x1E)";

			// used with searchPanes
			} else {
				$fields['categories'] = "categorylinks_t$firstKey.cl_to";
			}
			$tables['categorylinks_t0'] = 'categorylinks';
			$joins['categorylinks_t0'] = [ 'LEFT JOIN', [ "t$firstKey.page_id = categorylinks_t$firstKey.cl_from" ] ];
		}
*/
		if ( $this->params['categories'] && !$this->params['count-printout'] ) {
			$catAlias = "categorylinks_t$firstKey";
			$linkAlias = "linktarget_t$firstKey";

			// Use GROUP_CONCAT for aggregates, single column for searchPanes
			if ( !$this->params['count-categories'] ) {
				$fields['categories'] = $isNewSchema
					? "GROUP_CONCAT($linkAlias.lt_title SEPARATOR 0x1E)"
					: "GROUP_CONCAT($catAlias.cl_to SEPARATOR 0x1E)";
			} else {
				$fields['categories'] = $isNewSchema
					? "$linkAlias.lt_title"
					: "$catAlias.cl_to";
			}

			$tables[$catAlias] = 'categorylinks';
			if ( $isNewSchema ) {
				$tables[$linkAlias] = 'linktarget';
			}

			$joins[$catAlias] = [
				'LEFT JOIN',
				[ "t$firstKey.page_id = $catAlias.cl_from" ]
			];

			if ( $isNewSchema ) {
				$joins[$linkAlias] = [
					'LEFT JOIN',
					"$linkAlias.lt_id = $catAlias.cl_target_id"
				];
			}
		}
	}

	private function prepareQuery() {
		if ( empty( $this->AndConditions ) && !$this->conditionId ) {
			$this->errors[] = ( $this->conditionId === null ? 'no query' : 'unknown title' );
			return;
		}

		$this->schemaId = $this->databaseManager->getSchemaId( $this->params['schema'] );

		if ( $this->schemaId === null ) {
			$this->errors[] = 'no schema (' . $this->params['schema'] . ')';
			return;
		}

		$conds = [
			'schema_id' => $this->schemaId,
		];

		$res = $this->dbr->select(
			'visualdata_prop_tables',
			[ 'table_id', 'path_no_index' ],
			$conds,
			__METHOD__
		);

		if ( !$res->numRows() ) {
			$this->errors[] = 'schema has no data';
			return;
		}

		foreach ( $res as $row ) {
			$row = (array)$row;
			$tablename = $this->tableNameFromId( $row['table_id'] );
			$this->mapPathNoIndexTable[$row['path_no_index']] = $tablename;
		}

		$thisClass = $this;
		$parseEscapedPrintout = static function ( $varName, $key, $value ) use ( $thisClass ) {
			$replacements = [];
			if ( array_key_exists( $value, $thisClass->mapPathNoIndexTable ) ) {
				$thisClass->$varName[$key] = $value;

			} else {
				unset( $thisClass->$varName[$key] );

				// use another delimiter than / otherwise
				// str_replace( '/' below will replace the match
				// after the escape character
				$pattern = str_replace( '~', '~0', preg_quote( $value, '#' ) );

				// match both slash and escaped slash
				$pattern = str_replace( '/', '(?:~1|\\/)', $pattern );

				// treat author/name as author~1name
				foreach ( $thisClass->mapPathNoIndexTable as $k => $v ) {
					// @FIXME why this is necessary ? shouldn't be one to one match ?
					//  || preg_match( "/^$pattern\//", $k )

					if ( preg_match( "#^$pattern$#", $k ) ) {
						$thisClass->$varName[$key] = $k;
						$replacements[$value] = $k;

						// remove from printoutsOriginal
						// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
						if ( ( $k_ = array_search( $value, $thisClass->printoutsOriginal ) ) !== false ) {
							unset( $thisClass->printoutsOriginal[$k_] );
						}
						break;
					}
				}
			}
			return $replacements;
		};

		// remove non existing printouts, allow retrieving all
		// printouts of subitems, e.g. authors/first_name, authors/last_name
		// from |?authors, taking into account escaping of json pointers
		foreach ( $this->printouts as $key => $value ) {
			// $value = $this->databaseManager::escapeJsonPointerPart( $value );
			$parseEscapedPrintout( 'printouts', $key, $value );
		}

		foreach ( $this->conditionProperties as $key => $value ) {
			$replacements = $parseEscapedPrintout( 'conditionProperties', $key, $value );

			// replace same prop in $this->AndConditions
			foreach ( $this->AndConditions as $key => $values ) {
				foreach ( $values as $k => $v ) {
					if ( array_key_exists( $k, $replacements ) ) {
						$this->AndConditions[$key][$replacements[$k]] = $v;
						unset( $this->AndConditions[$key][$k] );
					}
				}
			}
		}

		if ( $this->params['printouts-from-conditions'] ) {
			foreach ( $this->AndConditions as $key => $values ) {
				foreach ( $values as $k => $v ) {
					$this->printouts[] = $k;
				}
			}
			return;
		}

		if ( $this->params['count-categories'] ) {
			return;
		}

		// retrieve all, but order according to the schema
		// descriptor
		if ( empty( $this->printouts ) ) {
			$this->printouts = $this->getAllOrderedPrintouts();
			$this->printoutsOriginal = $this->printouts;
		}
	}

	/**
	 * @return array
	 */
	private function getAllOrderedPrintouts() {
		$ret = array_keys( $this->mapPathNoIndexTable );

		$schemaStr = json_encode( $this->schema );
		usort( $ret, static function ( $a, $b ) use ( $schemaStr ) {
			// $aPos = strpos( $schemaStr, "\"name\":\"$a\"" );
			// $bPos = strpos( $schemaStr, "\"name\":\"$b\"" );
			// if ( $aPos === false ) {
			// 	$aPos = INF;
			// }
			// if ( $bPos === false ) {
			// 	$bPos =INF;
			// }

			$a = preg_quote( $a, '/' );
			$b = preg_quote( $b, '/' );

			preg_match( "/\"name\"\s*:\s*\"$a\"/", $schemaStr, $matches, PREG_OFFSET_CAPTURE );
			$aPos = ( !empty( $matches[0][1] ) ? $matches[0][1] : INF );

			preg_match( "/\"name\"\s*:\s*\"$b\"/", $schemaStr, $matches, PREG_OFFSET_CAPTURE );
			$bPos = ( !empty( $matches[0][1] ) ? $matches[0][1] : INF );

			if ( $aPos === $bPos ) {
				return 0;
			}
			return ( $aPos < $bPos ) ? -1 : 1;
		} );

		return $ret;
	}

	/**
	 * @param int $firstKey
	 * @param array $fields
	 * @param string|null $printoutKey
	 * @return string|null
	 */
	private function getGroupBY( $firstKey, $fields, $printoutKey ) {
		if ( $this->count && $this->treeFormat ) {
			return null;
		}

		if ( $this->params['count-printout'] ) {
			return $printoutKey;
		}

		if ( $this->params['count-categories'] ) {
			return 'categories';
		}

		if ( $this->treeFormat ) {
			return "t$firstKey.page_id";
		}

		// *** attention, this must not be performed with count
		if ( $this->params['categories'] ) {
			unset( $fields['categories'] );
			return implode( ', ', array_values( $fields ) );
		}

		return null;
	}

	/**
	 * @return array
	 */
	private function parseOrderBy() {
		$ret = [];
		// @see https://phabricator.wikimedia.org/T387008
		$values = \VisualData::splitString( $this->params['order'] );
		foreach ( $values as $v ) {
			// @see https://phabricator.wikimedia.org/T387008
			preg_match( '/^\s*+(.+?)\s*+(ASC|DESC)?\s*+$/i', $v, $match_ );
			$printout = $match_[1];
			$sort = $match_[2] ?? 'ASC';
			$ret[$printout] = $sort;
		}
		return $ret;
	}

	private function performQuery() {
		if ( count( $this->errors ) ) {
			return;
		}

		$arr = [];
		foreach ( $this->conditionProperties as $pathNoIndex ) {
			$arr[$pathNoIndex] = 0;
		}

		foreach ( $this->printouts as $pathNoIndex ) {
			$arr[$pathNoIndex] = 1;
		}

		uksort( $arr, static function ( $ka, $kb ) {
			$a = substr_count( $ka, '/' );
			$b = substr_count( $kb, '/' );
			return ( $a == $b ? 0 : (int)( $a > $b ) );
		} );

		$this->conditionProperties = array_intersect( $this->conditionProperties, array_keys( $this->mapPathNoIndexTable ) );

		if ( !$this->conditionId && !count( $this->conditionProperties ) && !count( $this->conditionSubjects ) ) {
			$this->errors[] = 'no valid conditions';
			return;
		}

		$arr = [];
		foreach ( $this->conditionProperties as $pathNoIndex ) {
			$arr[$pathNoIndex] = false;
		}

		foreach ( $this->printouts as $pathNoIndex ) {
			// *** this condition seems redundant, see above $parseEscapedPrintout
			if ( array_key_exists( $pathNoIndex, $this->mapPathNoIndexTable ) ) {
				$arr[$pathNoIndex] = true;
			}
		}

		$thisClass = $this;
		$parentSchemaIsarray = static function ( $printout ) use ( $thisClass ) {
			if ( !array_key_exists( $printout, $thisClass->mapPrintoutParentSchemas ) ) {
				$thisClass->mapPrintoutParentSchemas[$printout] = DatabaseManager::getParentSchemasOfPrintout( $thisClass->schema, $printout );
			}
			$parentSchemas = $thisClass->mapPrintoutParentSchemas[$printout];
			$subSchema = array_slice( $parentSchemas, -2, 1 );

			return (
				!empty( $subSchema[0] ) &&
				array_key_exists( 'type', $subSchema[0] ) &&
				$subSchema[0]['type'] === 'array'
			);
		};

		$orderBy = $this->parseOrderBy();
		foreach ( $orderBy as $printout_ => $sort_ ) {
			if ( !array_key_exists( $printout_, $arr )
				&& array_key_exists( $printout_, $this->mapPathNoIndexTable )
			) {
				$arr[$printout_] = false;
			}
		}

		if ( !count( $arr ) ) {
			if ( !$this->params['count-categories'] ) {
				$this->errors[] = 'no valid printouts';
				return;

			} else {
				$this->printouts = $this->getAllOrderedPrintouts();
				if ( !count( $this->printouts ) ) {
					$this->errors[] = 'no valid printouts';
					return;
				}
				$arr[$this->printouts[0]] = false;
			}
		}

		$items = [];
		foreach ( $arr as $pathNoIndex => $isPrintout ) {
			$depth = substr_count( $pathNoIndex, '/' );
			$isArray = $parentSchemaIsarray( $pathNoIndex );
			$printoutParent = substr( $pathNoIndex, 0, strrpos( $pathNoIndex, '/' ) );

			$items[] = [
				'printout' => $pathNoIndex,
				'printoutParent' => $printoutParent,
				'depth' => $depth,
				'isArray' => $isArray,
				'isPrintout' => $isPrintout,
				'isCondition' => in_array( $pathNoIndex, $this->conditionProperties ),
				'isOrderBy' => array_key_exists( $pathNoIndex, $orderBy )
			];
		}

		// *** attention, reset this array since
		// performQuery is called both for count or standard
		// results without reinstantiate the class
		$this->conditionsUseHaving = [];

		if ( $this->treeFormat && !$this->count ) {
			$maxDepthCondition = 0;
			foreach ( $items as $v ) {
				if ( $v['isCondition'] ) {
					if ( $v['depth'] > $maxDepthCondition ) {
						$maxDepthCondition = $v['depth'];
					}
				}
			}

			foreach ( $this->AndConditions as $i => $value ) {
				// use HAVING within a given condition (which can
				// be composed of multiple disjunctions)
				// if all of the disjunctions are LIKE (a use case for
				// this is Datatables search) and there is
				// at least one multiple item in the same set
				// -- if they aren't LIKE the match will fail
				// since is performed on the result of the GROUP_CONCAT,
				// not the single values
				$allLikes = true;
				$multipleItems = false;
				foreach ( $value as $printout => $values ) {
					foreach ( $values as $v ) {
						preg_match( '/^(!)?(~)?(.+?)(~)?$/', $v, $match );
						if ( empty( $match ) || ( empty( $match[2] ) && empty( $match[4] ) ) ) {
							$allLikes = false;
							break 2;
						}
					}

					// perform an additional check, keep using WHERE
					// if there are no multiple items in a set of
					// disjunctions
					if ( $allLikes && !$multipleItems ) {
						foreach ( $items as $v ) {
							if ( $v['printout'] === $printout &&
								$v['depth'] === $maxDepthCondition
							) {
								if ( $parentSchemaIsarray( $v['printout'] ) ) {
									$multipleItems = true;
									break;
								}
							}
						}
					}
				}

				if ( $allLikes && $multipleItems ) {
					$this->conditionsUseHaving[] = $i;
				}
			}
		}

		usort( $items, static function ( $a, $b ) {
			$aVal = $a['depth'] + (int)$a['isArray'];
			$bVal = $b['depth'] + (int)$b['isArray'];

			return ( $aVal == $bVal ? 0
				: (int)( $aVal > $bVal ) );
		} );

		$printoutToIndex = [];
		foreach ( $items as $index => $item ) {
			$printoutToIndex[$item['printout']] = $index;
		}

		$parents = [];
		foreach ( $items as $i => &$item ) {
			if ( isset( $printoutToIndex[$item['printoutParent']] ) ) {
				$parents[] = $item['printoutParent'];
			}

			if ( !$item['isArray'] ) {
				for ( $j = 0; $j < $i; $j++ ) {
					if ( empty( $items[$j]['printoutParent'] ) ) {
						continue;
					}
					if ( $items[$j]['isArray'] ) {
						continue;
					}
					if ( strpos( $item['printoutParent'], $items[$j]['printoutParent'] . '/' ) === 0 ) {
						$item['parentIndex'] = $j;
						break;
					}
				}

				// get first item at the same depth which is not an array
				for ( $j = 0; $j < $i; $j++ ) {
					if ( $items[$j]['isArray'] ) {
						continue;
					}
					if ( $item['printoutParent'] === $items[$j]['printoutParent'] ) {
						$item['topSiblingIndex'] = $j;
						break;
					}
				}

			} else {
				for ( $j = 0; $j < $i; $j++ ) {
					if ( $items[$j]['isArray'] ) {
						continue;
					}
					if ( empty( $items[$j]['printoutParent'] ) ) {
						continue;
					}
					if ( $item['printoutParent'] === $items[$j]['printoutParent'] ) {
						$item['parentIndex'] = $j;
						break;
					}
				}
			}
		}

		$secondaryPrintouts = [];
		if ( $this->treeFormat && !$this->conditionId ) {
			if ( count( $items ) > $GLOBALS['wgVisualDataQueryProcessorPrintoutsLimit'] ) {
				foreach ( $items as $i => $v ) {
					if ( !$v['isCondition'] && !$v['isOrderBy'] && !in_array( $i, $parents ) ) {
						$secondaryPrintouts[] = $v['printout'];
					}
				}
				foreach ( array_reverse( $items, true ) as $i => $v ) {
					if ( in_array( $v['printout'], $secondaryPrintouts ) ) {
						if ( count( $items ) > 1 ) {
							unset( $items[$i] );
						} else {
							unset( $secondaryPrintouts[array_search( $v['printout'], $secondaryPrintouts )] );
						}
					}
				}
			}
		}

		$firstKey = array_key_first( $items );

		// @todo instead of $firstKey use the following and rename
		// the indexes
		// $items = array_values( $items );

		$fields = [];
		$tables = [];
		$conds = [];
		$options = [];
		$joins = [];
		$having = [];

		$fields['page_id'] = "t$firstKey.page_id";
		// $conds["t$firstKey.schema_id"] = $this->schemaId;

		if ( $this->conditionId ) {
			$conds[] = "t$firstKey.page_id = " . $this->conditionId;
		}

		$mapConds = [];
		foreach ( $items as $key => $v ) {
			$pathNoIndex = $v['printout'];
			$isPrintout = $v['isPrintout'];
			$this->mapKeyToPrintout[$key] = $v['printout'];
			$tablename = $this->mapPathNoIndexTable[$pathNoIndex];
			$mapConds[$pathNoIndex] = [ 'key' => $key, 'tablename' => $tablename ];
			$joinConds = [];

			// @ATTENTION !!
			// the following query structure assumes that
			// the first queried printout always exists,
			// evaluate whether to use something like
			// "SELECT 1 FROM DUAL" instead

			// if ( $key === 0 ) {
			// 	$conds["t$key.path_no_index"] = $pathNoIndex;
			// } else {
			// 	$joinConds["t$key.path_no_index"] = $pathNoIndex;
			// }

			$conds_ = [
				'p.schema_id' => $this->schemaId,
				'p.path_no_index' => $pathNoIndex

/*
#update --- requires to bring the related WHERE condition inside the subquery
only if conditionsUseHaving is false

				'schema_id' => $this->schemaId,
				'path_no_index' => $pathNoIndex
*/
			];

			if ( $key > $firstKey ) {
				$joinConds[] = "t$key.page_id=t$firstKey.page_id";
				if ( $this->params['hierarchical-conditions'] ) {
					// @IMPORTANT!! otherwise, with locate between
					// identical strings, the query will not work!!
					// (it could be related to how mysql manages indexes)
					if ( isset( $v['topSiblingIndex'] ) ) {
						$parentIndex = $v['topSiblingIndex'];
						$joinConds[] = "t$key.path_parent = t$parentIndex.path_parent";

					} elseif ( isset( $v['parentIndex'] ) ) {
						$parentIndex = $v['parentIndex'];
						$joinConds[] = "LOCATE( t$parentIndex.path_parent, t$key.path_parent ) = 1";
					}
				}
				$joins["t$key"] = [ 'LEFT JOIN', $this->dbr->makeList( $joinConds, LIST_AND ) ];

			} else {
				if ( $this->conditionId ) {
					$conds_[] = 't.page_id = ' . $this->conditionId;
				}
			}

			$tables_ = [
				't' => "visualdata_$tablename",
				'p' => 'visualdata_props'
			];

/*
#update
			$fields_ = [
				't.page_id',
				'p.path_parent',
				't.value',
			];

			if ( $this->treeFormat ) {
				$fields_[] = "GROUP_CONCAT(p.path SEPARATOR 0x1e) AS p$key";
				$fields_[] = "GROUP_CONCAT(t.value SEPARATOR 0x1e) AS c$key";
			}
*/
			$fields_ = [ 't.value', 't.page_id', 'p.path_no_index', 'p.path', 'p.path_parent', 'p.schema_id' ];

			$options_ = [];
/*
#update
			if ( $this->treeFormat ) {
				$options_['GROUP BY'] = 't.page_id';
			}
*/

			// *** IMPORTANT use one of the following when appropriate !!
			// $options_['IGNORE INDEX'] = [ 'p' => [ 'path_parent', 'path_no_index', 'index_1', 'index_2', 'index_3' ] ];
			// FORCE INDEX
			// $options_['USE INDEX'] = [ 'p' => 'PRIMARY' ];

			$jconds_ = [
				'p' => [ 'JOIN', 'p.id=t.prop_id' ]
			];

			// @see ActiveUsersPager
			$tables["t$key"] = $this->dbr->buildSelectSubquery(
				$tables_,
				$fields_,
				$conds_,
				__METHOD__,
				$options_,
				$jconds_
			);

			if ( $isPrintout ) {
				if ( !$this->treeFormat ) {
					$fields["v$key"] = "t$key.value";

				} else {
					$fields["p$key"] = "GROUP_CONCAT(t$key.path SEPARATOR 0x1E)";
					$fields["c$key"] = "GROUP_CONCAT(t$key.value SEPARATOR 0x1E)";
/*
#update
					$fields[] = "p$key";
					$fields[] = "c$key";
*/
				}
			}

			if ( $this->treeFormat ) {
				if ( $v['isCondition'] ) {
/*
#update
					$fields[] = "c$key";
*/
					$fields["c$key"] = "GROUP_CONCAT(t$key.value SEPARATOR 0x1E)";
				}
			}
		}

		$this->getConditions( $firstKey, $mapConds, $conds, $tables, $joins, $fields, $having );

		$method = ( !$this->debug ? ( !$this->count ? 'select' : 'selectField' )
			: 'selectSQLText' );

		// if ( $this->count ) {
		// 	$method = 'selectSQLText';
		// }

		$options = $this->getOptions( $firstKey, $orderBy, $fields, $tables, $joins );

		// *** join always, it ensures that the related article exists
		// join page table also when sorting by mainlabel
		// if ( !empty( $options['ORDER BY'] )
		// 	&& strpos( $options['ORDER BY'], 'page_title' ) !== false
		// ) {
		// if ( !$this->params['count-printout'] ) {
			$tables['page_alias'] = 'page';
			$joins['page_alias'] = [ 'JOIN', [ "page_alias.page_id = t$firstKey.page_id" ] ];
		// }

/*
		if ( $this->params['categories'] && !$this->params['count-printout'] ) {
			if ( !$this->params['count-categories'] ) {
				$fields['categories'] = "GROUP_CONCAT(categorylinks_t$firstKey.cl_to SEPARATOR 0x1E)";

			// used with searchPanes
			} else {
				$fields['categories'] = "categorylinks_t$firstKey.cl_to";
			}
			$tables['categorylinks_t0'] = 'categorylinks';
			$joins['categorylinks_t0'] = [ 'LEFT JOIN', [ "t$firstKey.page_id = categorylinks_t$firstKey.cl_from" ] ];
		}
*/
		// used by datatables searchPanes
		$printoutKey = null;
		if ( $this->params['count-printout'] ) {
			$fields['count'] = 'COUNT(*)';

			foreach ( $items as $k => $v ) {
				if ( $v['isPrintout'] ) {
					$printoutKey = "v$k";
					break;
				}
			}

			if ( $this->params['count-printout-min'] > 1 ) {
				$options['HAVING'] = 'count >= ' . $this->params['count-printout-min'];
			}
		}

		if ( $this->params['count-categories'] ) {
			$fields['count'] = 'COUNT(*)';

			if ( $this->params['count-printout-min'] > 1 ) {
				$options['HAVING'] = 'count >= ' . $this->params['count-printout-min'];
			}
		}

		if ( count( $having ) ) {
			$options['HAVING'] = $having;
		}

		$groupBy = $this->getGroupBy( $firstKey, $fields, $printoutKey );
		if ( !empty( $groupBy ) ) {
			$options['GROUP BY'] = $groupBy;
		}

		if ( $this->count ) {
			$fields = [
				'count' => ( !$this->treeFormat ?
					'COUNT(*)' : "COUNT( DISTINCT ( t$firstKey.page_id ) )" )
			];
		}

		// prevents error "Error 1116: Too many tables"
		if ( count( $tables ) > 61 ) {
			$this->errors[] = 'Too many tables in the sql, try to specify at least one valid printout or less printouts';
			return;
		}

		$res = $this->dbr->$method(
			// tables
			$tables,
			// fields
			$fields,
			// where
			$conds,
			__METHOD__,
			// options
			$options,
			// join
			$joins
		);

		// this is necessary only if HAVING or group
		// is used for count as well
		// if ( $this->count ) {
		// 	$res = $this->dbr->query( "SELECT COUNT(*) as count FROM ( $res ) as q", __METHOD__ );
		// 	$res = $res->fetchObject()->count;
		// }

		if ( $this->debug ) {
			$this->result = (string)$res;
			return;
		}

		// used by datatables searchPanes
		if ( $this->params['count-printout'] ) {
			$ret = [];
			foreach ( $res as $row ) {
				$row = (array)$row;
				$ret[$row[$printoutKey]] = (int)$row['count'];
			}
			ksort( $ret );
			$this->result = $ret;
			return;
		}

		if ( $this->params['count-categories'] ) {
			$ret = [];
			foreach ( $res as $row ) {
				$row = (array)$row;
				$ret[$row['categories']] = $row['count'];
			}
			$this->result = $ret;
			return;
		}

		if ( $this->count ) {
			$this->result = (int)$res;
			return;
		}

		$this->result = [];

		if ( !$res->numRows() ) {
			return;
		}

		$thisClass = $this;
		$castType = static function ( $value, $printout ) use ( $thisClass ) {
			$tablename = $thisClass->mapPathNoIndexTable[$printout];
			$format = null;
			switch ( $tablename ) {
				case 'text':
				case 'textarea':
					$type = 'string';
					break;
				case 'date':
					$type = 'string';
					$format = 'date';
					break;
				case 'datetime':
					$type = 'string';
					$format = 'datetime';
					break;
				case 'time':
					$type = 'string';
					$format = 'time';
					break;
				case 'integer':
					$type = 'integer';
					break;
				case 'numeric':
					$type = 'number';
					break;
				case 'boolean':
					$type = 'boolean';
					break;
			}
			$schema = [
				'type' => $type,
				'format' => $format
			];
			SchemaProcessor::castType( $value, $schema, true );
			return $value;
		};

		$params_ = [
			'schema' => $this->params['schema'],
			'limit' => 1,
			'offset' => 0,
			'order' => '',
			'pagetitle' => '',
			'hierarchical-conditions' => true,
			'printouts-from-conditions' => false,
			'categories' => false,
			'count-printout' => false,
			'count-printout-min' => 1,
			'count-categories' => false,
			'debug' => false,
			'secondary-printouts' => true
		];

		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		$separator = chr( hexdec( '0x1E' ) );
		$titles = [];

		// @todo implement secondaryPrintouts query for plain format
		// because there could be more than one row per page, this requires
		// that the output is grouped by page_id and then to perform
		// the new query as below. Merge each returned row with all the
		// rows of a given page, and if there are more rows, append them
		// after the rows of the current page
		$badTitle = \SpecialPage::getTitleFor( 'Badtitle' );
		foreach ( $res as $row ) {
			$categories = [];
			$row = (array)$row;
			$row_ = [];
			$pageId = $row['page_id'];
			unset( $row['page_id'] );

			if ( !array_key_exists( $pageId, $titles ) ) {
				$title_ = TitleClass::newFromID( $pageId );
				$titles[$pageId] = [
					$title_,
					$permissionManager->userCan( 'read', $this->user, $title_ )
				];
			}

			[ $title_, $canRead_ ] = $titles[$pageId];

			// *** fill-in with empty row to comply
			// with count and pagination (offset, limit values)
			if ( !$canRead_ ) {
				$this->result[] = [
					$badTitle,
					array_fill_keys( $this->printoutsOriginal, '' ),
					[]
				];
				continue;
			}

			if ( !empty( $row['categories'] ) ) {
				$categories = array_values( array_unique( explode( $separator, str_replace( '_', ' ', $row['categories'] ) ) ) );
			}
			unset( $row['categories'] );

			if ( !$this->treeFormat ) {
				// important, this ensures rows have same
				// number of fields
				$row_ = array_fill_keys( $this->printoutsOriginal, '' );

				foreach ( $row as $k => $v ) {
					// $v can be 0 (numeric value)
					// if ( empty( $v ) ) {
					// 	continue;
					// }
					$index = substr( $k, 1 );
					$printout = $this->mapKeyToPrintout[$index];
					$row_[$printout] = $castType( $v, $printout );
				}

			} else {
				foreach ( $this->mapKeyToPrintout as $key => $printout ) {
					if ( empty( $row["p$key"] ) ) {
						continue;
					}
					$paths = explode( $separator, $row["p$key"] );
					$values = array_slice( explode( $separator, (string)$row["c$key"] ), 0, count( $paths ) );
					$paths = array_slice( $paths, 0, count( $values ) );
					foreach ( $paths as $key => $path ) {
						$row_[$path] = $castType( $values[$key], $printout );
					}
				}

				if ( $this->params['secondary-printouts'] ) {
					$this->result = $row_;
					continue;
				}

				if ( count( $secondaryPrintouts ) ) {
					$queryProcessor_ = new QueryProcessor( $this->user, $this->schema, $pageId, $secondaryPrintouts, $params_ );
					$method_ = ( !$this->treeFormat ? 'getResults' : 'getResultsTree' );
					$results_ = $queryProcessor_->$method_();
					$row_ = array_merge( $row_, $results_ );
				}
			}

			$this->result[] = [
				$title_,
				\VisualData::plainToNested( $row_, true ),
				$categories
			];
		}

		// *** return the first result
		// only if required using a parameter
		// if ( $this->conditionId && count( $this->result ) ) {
		// 	$this->result = $this->result[0];
		// }
	}
}

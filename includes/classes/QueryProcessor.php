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

use MediaWiki\Extension\JsonForms\ParametersProcessor;
use MediaWiki\Extension\JsonForms\SchemaUtils;
use MediaWiki\Extension\VisualData\Aliases\Title as TitleClass;
use MediaWiki\Extension\VisualData\Utils\DateParser;
use MediaWiki\MediaWikiServices;

class QueryProcessor {

	// visualdata_<table> id → logical type name.
	private const TABLE_ID_TO_NAME = [
		1 => 'text',
		2 => 'textarea',
		3 => 'date',
		4 => 'datetime',
		5 => 'time',
		6 => 'integer',
		7 => 'numeric',
		8 => 'boolean',
	];

	// Comparators understood by conditionToSQL(), matching the
	// JsonSchema:VisualData/AskQueryCondition COMPARATOR_LABELS.
	// '!=' is a runtime alias for '!' and is normalised before the list is checked.
	private const COMPARISONS = [ '=', '!', '>', '>=', '<', '<=' ];

	// LIKE comparators from COMPARATOR_MAP (string type only).
	private const LIKE_COMPARATORS = [ '~x', '~x~', 'x~' ];

	// SQL GROUP_CONCAT separator (0x1E = record separator).
	private const GROUP_CONCAT_SEPARATOR = 'SEPARATOR 0x1E';

	/** @var User */
	private $user;

	/** @var array */
	private $schema = [];

	/** @var array */
	private $schemaMetadata = [];

	/** @var array<string, array> cleanPath → schema node (lazy built) */
	private $schemaIndex = null;

	/** @var array|string|int */
	private $result;

	/** @var array */
	private $resultsList = [];

	/** @var string[] */
	private $conditionProperties = [];

	/** @var string[] */
	private $conditionSubjects = [];

	/** @var string[] */
	private $printouts = [];

	/** @var string[] */
	private $printoutsOriginal = [];

	/** @var array */
	private $params = [];

	/** @var array<string, array<string, array>> */
	private $conditionMeta = [];

	/** @var IReadableDatabase */
	private $dbr;

	/** @var bool */
	private $count;

	/** @var bool */
	private $treeFormat;

	/** @var array<int, array<string, string[]>> */
	private $AndConditions = [];

	/** @var int|null */
	private $conditionId = null;

	/** @var string|null */
	private $query = null;

	/** @var DatabaseManager */
	private $databaseManager;

	/** @var string[] */
	private $errors = [];

	/** @var array<int, string> */
	private $mapKeyToPrintout = [];

	/** @var array<string, string> */
	private $mapPathNoIndexTable = [];

	/** @var array<string, bool> */
	private $mapPrintoutParentSchemas = [];

	/** @var int[] */
	private $conditionsUseHaving = [];

	/** @var int */
	private $schemaId = 0;

	/** @var bool */
	private $debug;

	/** @var string */
	private $sep = '.';

	/** @var string[] */
	private $specialPrefixes = [
		'Creation date',
		'CreationDate',
		'Modification date',
		'ModificationDate',
	];

	/**
	 * @param User $user
	 * @param array $schemaMetadata
	 * @param array $params
	 */
	public function __construct( $user, $schemaMetadata, $params ) {
		$paramsSchema = [
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
			'secondary-printouts' => [ false, 'bool' ],
		];

		$params = ParametersProcessor::applySchemaDefaults( $params, $paramsSchema );

		$this->debug = $params['debug'];
		$this->params = $params;
		$this->user = $user;
		$this->printouts = $params['printouts'];
		$this->printoutsOriginal = $params['printouts'];
		$this->schemaMetadata = $schemaMetadata;
		$this->databaseManager = new DatabaseManager();

		$this->dbr = \JsonForms::getDB( DB_REPLICA );
		$this->parseQuery();

		$title = TitleClass::newFromText( 'JsonSchema:' . $params['schema'] );
		$this->schemaId = $title->getArticleID();

		$this->prepareQuery();
	}

	// ---------------------------------------------------------------------
	// Schema index
	// ---------------------------------------------------------------------

	/**
	 * Build (once) the cleanPath → schema-node index from
	 * $this->schemaMetadata['processedSchema'].
	 *
	 * @return array<string, array>
	 */
	private function schemaIndex(): array {
		if ( $this->schemaIndex !== null ) {
			return $this->schemaIndex;
		}

		$this->schemaIndex = [];
		$processed = $this->schemaMetadata['processedSchema'] ?? [];
		if ( !is_array( $processed ) ) {
			return $this->schemaIndex;
		}

		foreach ( $processed as $entry ) {
			$entry = (array)$entry;
			$cp = $entry['cleanPath'] ?? null;
			if ( $cp !== null && $cp !== '' ) {
				$this->schemaIndex[ $cp ] = $entry;
			}
		}

		return $this->schemaIndex;
	}

	/**
	 * Schema node for a printout (by cleanPath).
	 */
	private function schemaEntryForPrintout( string $printout ): ?array {
		return $this->schemaIndex()[ $printout ] ?? null;
	}

	// ---------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------

	public function getCount() {
		$this->count = true;
		$this->treeFormat = false;

		$categories = $this->params['categories'];
		$this->params['categories'] = false;
		$this->performQuery();
		$this->params['categories'] = $categories;

		return count( $this->errors ) ? -1 : (int)$this->result;
	}

	public function getErrors() {
		return $this->errors;
	}

	public function getValidPrintouts() {
		return $this->printouts;
	}

	public function getSchemaMetadata() {
		return $this->schemaMetadata;
	}

	public function isPrintCondition() {
		return !empty( $this->conditionId );
	}

	public function getCountTree() {
		$this->count = true;
		$this->treeFormat = true;
		$this->performQuery();
		return count( $this->errors ) ? -1 : (int)$this->result;
	}

	public function getResults() {
		$this->count = false;
		$this->treeFormat = false;
		$this->performQuery();
		return count( $this->errors ) ? [] : $this->result;
	}

	public function getResultsTree() {
		$this->count = false;
		$this->treeFormat = true;
		$this->performQuery();
		return count( $this->errors ) ? [] : $this->result;
	}

	public function getQueryData() {
		return [
			'query' => $this->query,
			'printouts' => $this->printouts,
			'params' => $this->params,
		];
	}

	// ---------------------------------------------------------------------
	// Condition parsing
	// ---------------------------------------------------------------------

	private function parseQuery(): void {
		$query = $this->params['query'];

		if ( is_int( $query ) || ( is_string( $query ) && ctype_digit( $query ) ) ) {
			$this->conditionId = (int)$query;
			return;
		}

		if ( is_string( $query ) && $query !== '' ) {
			$title = TitleClass::newFromText( trim( $query ) );
			if ( $title ) {
				$this->conditionId = $title->getArticleID();
			}
			return;
		}

		$this->parseConditions();
	}

	private function parseConditions(): void {
		$query = $this->params['query'] ?? null;

		if ( !is_array( $query ) ) {
			return;
		}

		$items = $query['conditions'] ?? null;

		if ( !is_array( $items ) ) {
			$this->parseSimpleConditions( $query );
			return;
		}

		foreach ( $items as $item ) {
			$item = $this->asArray( $item );
			if ( $item === null ) {
				continue;
			}

			if ( isset( $item['or'] ) && is_array( $item['or'] ) ) {
				$group = $this->buildAndGroup( $item['or'], true );
				if ( $group !== [] ) {
					$this->AndConditions[] = $group;
				}
				continue;
			}

			$group = $this->buildAndGroup( [ $item ], false );
			if ( $group !== [] ) {
				$this->AndConditions[] = $group;
			}
		}
	}

	private function parseSimpleConditions( array $query ): void {
		$group = [];

		foreach ( $query as $prop => $value ) {
			if ( $prop === 'mode' || $prop === 'conditions' ) {
				continue;
			}

			if ( $prop === 'page_title' ) {
				foreach ( (array)$value as $title ) {
					$title = trim( (string)$title );
					if ( $title === '' ) {
						continue;
					}
					$group['page_title'][] = $title;
					$this->conditionSubjects[] = $title;
				}
				continue;
			}

			$prop = trim( (string)$prop );
			if ( $prop === '' || $value === null ) {
				continue;
			}

			$group[ $prop ][] = trim( (string)$value );
			$this->conditionProperties[] = $prop;
		}

		if ( $group !== [] ) {
			$this->AndConditions[] = $group;
		}
	}

	/**
	 * @param array $orList
	 * @param bool $isOrGroup true when the list is the body of an { or: [...] }
	 * @return array<string, string[]>
	 */
	private function buildAndGroup( array $orList, bool $isOrGroup = false ): array {
		$group = [];

		$validComparators = array_merge(
			self::COMPARISONS,
			self::LIKE_COMPARATORS,
			[ '+', '-', '!=' ]  // runtime alias accepted for backward compat
		);

		foreach ( $orList as $cond ) {
			$cond = $this->asArray( $cond );
			if ( $cond === null ) {
				continue;
			}

			$prop = isset( $cond['property'] ) ? trim( (string)$cond['property'] ) : '';
			$comparator = $cond['comparator'] ?? '=';
			$value = $cond['value'] ?? null;

			// Subject-only condition: page_title via value
			if ( $prop === '' ) {
				if ( $value !== null && trim( (string)$value ) !== '' ) {
					$title = trim( (string)$value );
					$group['page_title'][] = $title;
					$this->conditionSubjects[] = $title;
				}
				continue;
			}

			// Special prefix: Creation date / Modification date.
			// These attach a revision join, so they must not appear inside
			// an OR group (would force the whole group to AND).
			if ( in_array( $prop, $this->specialPrefixes, true ) ) {
				if ( $isOrGroup ) {
					$this->errors[] = 'special_prefix_not_allowed_in_or:' . $prop;
					continue;
				}
				// Special prefixes only support the numeric/date comparators.
				if ( !in_array( $comparator, self::COMPARISONS, true ) ) {
					$this->errors[] = 'invalid_comparator_for_special_prefix:' . $prop;
					continue;
				}
				if ( $value === null ) {
					continue;
				}
				$value = trim( (string)$value );
				if ( $value === '' ) {
					continue;
				}
				// Canonicalise to the spaced form so getConditions can
				// match by in_array() without checking aliases.
				$canonical = $this->canonicalSpecialPrefix( $prop );
				$group[ $canonical ][] = $value;
				$this->conditionProperties[] = $canonical;
				$this->conditionMeta[ $canonical ][ $value ] =
					$this->makeConditionMeta( $cond, $comparator, $value );
				continue;
			}

			// Validate the comparator against the schema's COMPARATOR_MAP.
			if ( !in_array( $comparator, $validComparators, true ) ) {
				$this->errors[] = 'invalid_comparator:' . $prop . ':' . $comparator;
				continue;
			}

			$this->conditionProperties[] = $prop;

			if ( $comparator === '+' || $comparator === '-' ) {
				$group[ $prop ][] = '';
				$this->conditionMeta[ $prop ]['' ] = $this->makeConditionMeta( $cond, $comparator, null );
				continue;
			}

			if ( $value === null ) {
				continue;
			}
			$value = trim( (string)$value );
			if ( $value === '' ) {
				continue;
			}

			$group[ $prop ][] = $value;
			$this->conditionMeta[ $prop ][ $value ] = $this->makeConditionMeta( $cond, $comparator, $value );
		}

		return $group;
	}

	private function canonicalSpecialPrefix( string $prop ): string {
		switch ( $prop ) {
			case 'CreationDate':
				return 'Creation date';
			case 'ModificationDate':
				return 'Modification date';
		}
		return $prop;
	}

	private function makeConditionMeta( array $cond, string $comparator, ?string $value ): array {
		return [
			'comparator' => $comparator,
			'types' => $cond['types'] ?? [],
			'types_string' => $cond['types_string'] ?? null,
			'value' => $value,
		];
	}

	private function asArray( $value ): ?array {
		if ( is_object( $value ) ) {
			$value = json_decode( json_encode( $value ), true );
		}
		return is_array( $value ) ? $value : null;
	}

	// ---------------------------------------------------------------------
	// Lookups / casting
	// ---------------------------------------------------------------------

	private function tableNameFromId( $table_id ): ?string {
		return self::TABLE_ID_TO_NAME[ $table_id ] ?? null;
	}

	private function getSpecialPrefixOrderFunc( $prefix ): string {
		switch ( $prefix ) {
			case 'ModificationDate':
			case 'Modification date':
				return 'max';
			case 'CreationDate':
			case 'Creation date':
			default:
				return 'min';
		}
	}

	/**
	 * @param string $dataType
	 * @param mixed &$val
	 */
	private function castValAndQuote( $dataType, &$val ) {
		switch ( $dataType ) {
			case 'integer':
				$val = filter_var( $val, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE );
				settype( $val, 'integer' );
				return;

			case 'numeric':
				$val = filter_var( $val, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );
				settype( $val, 'float' );
				return;

			case 'date':
			case 'datetime':
			case 'time':
				$format = $this->dateFormatFor( $dataType );
				$dateParser = new DateParser( $val );
				$val = date( $format, $dateParser->parse() );
				$val = $this->dbr->addQuotes( $val );
				return;

			case 'boolean':
				$val = filter_var( $val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				settype( $val, 'boolean' );
				$val = $val ? 'TRUE' : 'FALSE';
				return;

			case 'text':
			case 'textarea':
			default:
				settype( $val, 'string' );
				$val = $this->dbr->addQuotes( $val );
		}
	}

	private function dateFormatFor( string $dataType ): string {
		switch ( $dataType ) {
			case 'date': return 'Y-m-d';
			case 'datetime': return 'Y-m-d H:i:s';
			case 'time': return 'H:i:s';
		}
		return 'Y-m-d H:i:s';
	}

	private function isValidData( $tableName, $value, ?string $printout = null, bool $forLike = false ): bool {
		$entry = ( $printout !== null && !$forLike ) ? $this->schemaEntryForPrintout( $printout ) : null;

		if ( $entry !== null ) {
			return $this->isValidAgainstSchema( $entry, $value );
		}

		return $this->isValidAgainstTableType( $tableName, $value );
	}

	private function isValidAgainstSchema( array $entry, $value ): bool {
		$declaredType = $entry['type'] ?? null;
		if ( $declaredType !== null && !$this->valueMatchesType( $value, $declaredType ) ) {
			return false;
		}

		if ( isset( $entry['enum'] ) && is_array( $entry['enum'] ) && count( $entry['enum'] ) > 0 ) {
			if ( !in_array( $value, $entry['enum'], true ) ) {
				return false;
			}
		}

		if ( is_string( $value ) ) {
			$format = $entry['format'] ?? null;
			switch ( $format ) {
				case 'email':
					if ( !filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
						return false;
					}
					break;
				case 'uri':
				case 'url':
					if ( !filter_var( $value, FILTER_VALIDATE_URL ) ) {
						return false;
					}
					break;
				case 'date':
					if ( !$this->matchesFormat( 'Y-m-d', $value ) ) {
						return false;
					}
					break;
				case 'date-time':
				case 'datetime':
					$ok = false;
					foreach ( [ 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP' ] as $fmt ) {
						if ( $this->matchesFormat( $fmt, $value ) ) {
							$ok = true;
							break;
						}
					}
					if ( !$ok ) {
						return false;
					}
					break;
				case 'time':
					if ( !$this->matchesFormat( 'H:i:s', $value ) ) {
						return false;
					}
					break;
			}

			if ( !empty( $entry['pattern'] ) ) {
				$regex = '/^(?:' . str_replace( '/', '\/', $entry['pattern'] ) . ')$/u';
				if ( @preg_match( $regex, $value ) !== 1 ) {
					return false;
				}
			}

			$len = mb_strlen( $value, 'UTF-8' );
			if ( isset( $entry['minLength'] ) && $len < (int)$entry['minLength'] ) {
				return false;
			}
			if ( isset( $entry['maxLength'] ) && $len > (int)$entry['maxLength'] ) {
				return false;
			}
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $entry['minimum'] ) && $value < $entry['minimum'] ) {
				return false;
			}
			if ( isset( $entry['maximum'] ) && $value > $entry['maximum'] ) {
				return false;
			}
		}

		return true;
	}

	private function valueMatchesType( $value, string $declaredType ): bool {
		switch ( $declaredType ) {
			case 'string':
				return is_string( $value ) || is_int( $value ) || is_float( $value );
			case 'integer':
				return is_int( $value );
			case 'number':
			case 'numeric':
				return is_int( $value ) || is_float( $value );
			case 'boolean':
				return is_bool( $value );
			case 'array':
				return is_array( $value );
			case 'object':
				return is_object( $value ) || ( is_array( $value ) && !$this->isList( $value ) );
			case 'null':
				return $value === null;
			default:
				return true;
		}
	}

	private function isValidAgainstTableType( string $tableName, $value ): bool {
		switch ( $tableName ) {
			case 'text':
				return is_string( $value ) || is_int( $value ) || is_float( $value );
			case 'textarea':
				return is_string( $value );
			case 'integer':
				return null !== filter_var( $value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE );
			case 'numeric':
				return null !== filter_var( $value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );
			case 'boolean':
				return null !== filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			case 'time':
			case 'date':
			case 'datetime':
				return is_string( $value ) || is_int( $value );
			default:
				return $tableName === gettype( $value );
		}
	}

	private function matchesFormat( string $format, string $value ): bool {
		$dt = \DateTimeImmutable::createFromFormat( $format, $value );
		return $dt instanceof \DateTimeImmutable && $dt->format( $format ) === $value;
	}

	private function isList( array $value ): bool {
		if ( $value === [] ) {
			return true;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	// ---------------------------------------------------------------------
	// SQL rendering
	// ---------------------------------------------------------------------

	private function conditionToSQL(
		$value,
		$field,
		$tableName = 'text',
		$comparator = '=',
		$callbackValue = null,
		?string $printout = null
	) {
		$value = trim( (string)$value );

		// Normalise the runtime alias before any dispatch.
		if ( $comparator === '!=' ) {
			$comparator = '!';
		}

		// ---------- Comparator-only: no value needed ----------
		if ( $comparator === '+' ) {
			return "$field IS NOT NULL";
		}
		if ( $comparator === '-' ) {
			return "$field IS NULL";
		}

		// ---------- LIKE family ----------
		if ( in_array( $comparator, self::LIKE_COMPARATORS, true ) ) {
			$value = $callbackValue ? $callbackValue( $value ) : $value;

			if ( !$this->isValidData( $tableName, $value, $printout, true ) ) {
				return false;
			}

			$any = $this->dbr->anyString();
			switch ( $comparator ) {
				case '~x':  // starts with
					$quoted = $this->dbr->buildLike( $value, $any );
					break;
				case 'x~':  // ends with
					$quoted = $this->dbr->buildLike( $any, $value );
					break;
				case '~x~': // contains
				default:
					$quoted = $this->dbr->buildLike( $any, $value, $any );
					break;
			}

			return "{$field} {$quoted}";
		}

		// ---------- Comparison operators ----------
		if ( !in_array( $comparator, self::COMPARISONS, true ) ) {
			// Unknown comparator → drop the condition
			return false;
		}

		$value = $callbackValue ? $callbackValue( $value ) : $value;
		if ( !$this->isValidData( $tableName, $value, $printout ) ) {
			return false;
		}

		$this->castValAndQuote( $tableName, $value );

		$operator = ( $comparator === '!' ) ? '!=' : $comparator;

		return "$field $operator $value";
	}

	private function processTitle(
		$firstKey,
		$value,
		&$orConds,
		&$tables,
		&$joins,
		&$fields,
		&$categories
	) {
		$title = TitleClass::newFromText( $value );
		if ( $title && ( $title->isKnown() || $title->getNamespace() === NS_CATEGORY ) ) {
			if ( $title->getNamespace() !== NS_CATEGORY ) {
				$orConds[] = "t$firstKey.page_id = " . $title->getArticleID();
			} else {
				$categories[] = $title;
			}
			return;
		}

		$value = str_replace( ' ', '_', $value );
		$nsIndex = \VisualData::getRegisteredNamespace( $value );

		$conds = [];
		if ( $nsIndex !== NS_MAIN ) {
			$conds[] = "page_alias.page_namespace = $nsIndex";
		}
		$conds[] = $this->conditionToSQL(
			$value,
			$this->fieldCaseInsensitive( 'page_alias.page_title' ),
			'text',
			'='
		);
		$orConds[] = $this->dbr->makeList( $conds, LIST_AND );
	}

	private function addSpecialPrefixJoin(
		$firstKey,
		$specialPrefix,
		$prefixValue,
		$prefixOperator,
		&$tables,
		&$joins
	): bool {
		$time = strtotime( $prefixValue );
		if ( $time === false ) {
			return false;
		}
		if ( !in_array( $prefixOperator, self::COMPARISONS, true ) ) {
			return false;
		}

		$orderFunc = $this->getSpecialPrefixOrderFunc( $specialPrefix );
		$timestamp = $this->dbr->timestamp( $time );

		$subquery = $this->dbr->buildSelectSubquery(
			[ 'revision' ],
			[ 'rev_page', 'timestamp' => "$orderFunc(rev_timestamp)" ],
			[],
			__METHOD__,
			[ 'GROUP BY' => 'rev_page' ]
		);

		$tables['rev_alias'] = $subquery;
		$joins['rev_alias'] = [ 'JOIN', [
			"rev_alias.rev_page = t$firstKey.page_id",
			"rev_alias.timestamp $prefixOperator $timestamp",
		] ];

		return true;
	}

	private function fieldCaseInsensitive( $fullColumnName ): string {
		return "CONVERT($fullColumnName USING utf8mb4) COLLATE utf8mb4_general_ci";
	}

	// ---------------------------------------------------------------------
	// Conditions → SQL
	// ---------------------------------------------------------------------

	private function getConditions( $firstKey, $mapConds, &$conds, &$tables, &$joins, &$fields, &$having ) {
		$isNewSchema = version_compare( MW_VERSION, '1.45', '>=' );

		foreach ( $this->AndConditions as $i => $value ) {
			$orConds = [];
			$categories = [];
			$havingConds = [];
			$useHaving = in_array( $i, $this->conditionsUseHaving );

			foreach ( $value as $printout => $values ) {
				// Special prefixes: Creation date / Modification date.
				// Attach the revision join; no WHERE / HAVING entry.
				if ( in_array( $printout, $this->specialPrefixes, true ) ) {
					foreach ( $values as $v ) {
						$comparator = $this->conditionMeta[ $printout ][ $v ]['comparator'] ?? '=';
						$this->addSpecialPrefixJoin(
							$firstKey,
							$printout,
							$v,
							$comparator,
							$tables,
							$joins
						);
					}
					continue;
				}

				if ( array_key_exists( $printout, $mapConds ) ) {
					$field = $useHaving
						? "c{$mapConds[$printout]['key']}"
						: "t{$mapConds[$printout]['key']}.value";
					$tablename = $mapConds[$printout]['tablename'];

					foreach ( $values as $v ) {
						$comparator = $this->conditionMeta[ $printout ][ $v ]['comparator'] ?? '=';
						$condStr = $this->conditionToSQL(
							$v,
							$field,
							$tablename,
							$comparator,
							null,
							$printout
						);
						if ( $condStr === false ) {
							continue;
						}
						if ( $useHaving ) {
							$havingConds[] = $condStr;
						} else {
							$orConds[] = $condStr;
						}
					}
					continue;
				}

				if ( $printout === 'page_title' ) {
					foreach ( $values as $v ) {
						$this->processTitle( $firstKey, $v, $orConds, $tables, $joins, $fields, $categories );
					}
				}
			}

			if ( count( $categories ) ) {
				$this->addCategoryJoin(
					$firstKey, $i, $categories, $isNewSchema, $useHaving,
					$orConds, $havingConds, $tables, $joins, $fields
				);
			}

			if ( count( $orConds ) ) {
				$conds[] = $this->dbr->makeList( $orConds, LIST_OR );
			}
			if ( count( $havingConds ) ) {
				$having[] = $this->dbr->makeList( $havingConds, LIST_OR );
			}
		}

		if ( $this->params['categories'] && !$this->params['count-printout'] ) {
			$this->addCategoriesField( $firstKey, $isNewSchema, $tables, $joins, $fields );
		}
	}

	private function addCategoryJoin(
		$firstKey,
		$i,
		array $categories,
		bool $isNewSchema,
		bool $useHaving,
		array &$orConds,
		array &$havingConds,
		array &$tables,
		array &$joins,
		array &$fields
	): void {
		$catAlias = "categorylinks_$i";
		$linkAlias = "linktarget_$i";

		$categoryConds = [];
		foreach ( $categories as $title ) {
			$categoryField = $isNewSchema
				? $this->fieldCaseInsensitive( "$linkAlias.lt_title" )
				: $this->fieldCaseInsensitive( "$catAlias.cl_to" );

			$categoryConds[] = $this->conditionToSQL( $title->getDbKey(), $categoryField, 'text', '=' );
		}

		$tables[$catAlias] = 'categorylinks';
		if ( $isNewSchema ) {
			$tables[$linkAlias] = 'linktarget';
		}

		$joins[$catAlias] = [ 'LEFT JOIN', "$catAlias.cl_from = t0.page_id" ];

		if ( $isNewSchema ) {
			$joins[$linkAlias] = [ 'LEFT JOIN', "$linkAlias.lt_id = $catAlias.cl_target_id" ];

			if ( $useHaving ) {
				$havingConds[] = $this->dbr->makeList( $categoryConds, LIST_OR );
			} else {
				$orConds[] = $this->dbr->makeList( $categoryConds, LIST_OR );
			}
			return;
		}

		$joins[$catAlias] = [
			'LEFT JOIN',
			$this->dbr->makeList( [
				"$catAlias.cl_from = t0.page_id",
				$this->dbr->makeList( $categoryConds, LIST_OR ),
			], LIST_AND ),
		];

		if ( $useHaving ) {
			$havingConds[] = "t$firstKey.page_id = $catAlias.cl_from";
			$fields[] = "$catAlias.cl_from";
		} else {
			$orConds[] = "t$firstKey.page_id = $catAlias.cl_from";
		}
	}

	private function addCategoriesField(
		$firstKey,
		bool $isNewSchema,
		array &$tables,
		array &$joins,
		array &$fields
	): void {
		$catAlias = "categorylinks_t$firstKey";
		$linkAlias = "linktarget_t$firstKey";

		if ( !$this->params['count-categories'] ) {
			$fields['categories'] = $isNewSchema
				? "GROUP_CONCAT($linkAlias.lt_title " . self::GROUP_CONCAT_SEPARATOR . ")"
				: "GROUP_CONCAT($catAlias.cl_to " . self::GROUP_CONCAT_SEPARATOR . ")";
		} else {
			$fields['categories'] = $isNewSchema
				? "$linkAlias.lt_title"
				: "$catAlias.cl_to";
		}

		$tables[$catAlias] = 'categorylinks';
		if ( $isNewSchema ) {
			$tables[$linkAlias] = 'linktarget';
		}

		$joins[$catAlias] = [ 'LEFT JOIN', [ "t$firstKey.page_id = $catAlias.cl_from" ] ];
		if ( $isNewSchema ) {
			$joins[$linkAlias] = [ 'LEFT JOIN', "$linkAlias.lt_id = $catAlias.cl_target_id" ];
		}
	}

	// ---------------------------------------------------------------------
	// Query preparation
	// ---------------------------------------------------------------------

	private function prepareQuery() {
		if ( empty( $this->AndConditions ) && !$this->conditionId ) {
			$this->errors[] = ( $this->conditionId === null ? 'no query' : 'unknown title' );
			return;
		}

		$res = $this->dbr->select(
			'visualdata_prop_tables',
			[ 'table_id', 'cleanpath' ],
			[ 'schema_id' => $this->schemaId ],
			__METHOD__
		);

		if ( !$res->numRows() ) {
			$this->errors[] = 'schema has no data';
			return;
		}
		foreach ( $res as $row ) {
			$row = (array)$row;
			$this->mapPathNoIndexTable[ $row['cleanpath'] ] = $this->tableNameFromId( $row['table_id'] );
		}

		$this->filterUnknownPrintouts();
		$this->filterUnknownConditionProperties();

		if ( $this->params['printouts-from-conditions'] ) {
			foreach ( $this->AndConditions as $values ) {
				foreach ( array_keys( $values ) as $k ) {
					$this->printouts[] = $k;
				}
			}
			return;
		}

		if ( $this->params['count-categories'] ) {
			return;
		}

		if ( empty( $this->printouts ) ) {
			$this->printouts = $this->getAllOrderedPrintouts();
			$this->printoutsOriginal = $this->printouts;
		}
	}

	private function filterUnknownPrintouts(): void {
		foreach ( $this->printouts as $key => $value ) {
			if ( !array_key_exists( $value, $this->mapPathNoIndexTable ) ) {
				unset( $this->printouts[ $key ] );
			}
		}
	}

	private function filterUnknownConditionProperties(): void {
		foreach ( $this->conditionProperties as $key => $value ) {
			// Special prefixes never appear in visualdata_prop_tables but are
			// handled by getConditions via addSpecialPrefixJoin.
			if ( in_array( $value, $this->specialPrefixes, true ) ) {
				continue;
			}
			if ( !array_key_exists( $value, $this->mapPathNoIndexTable ) ) {
				unset( $this->conditionProperties[ $key ] );
			}
		}
	}

	private function getAllOrderedPrintouts(): array {
		$ret = array_keys( $this->mapPathNoIndexTable );
		$schemaStr = json_encode( $this->schema );

		usort( $ret, static function ( $a, $b ) use ( $schemaStr ) {
			$aPos = self::positionInSchema( $a, $schemaStr );
			$bPos = self::positionInSchema( $b, $schemaStr );
			return $aPos === $bPos ? 0 : ( $aPos < $bPos ? -1 : 1 );
		} );

		return $ret;
	}

	private static function positionInSchema( string $name, string $schemaStr ): float {
		$pattern = '/"name"\s*:\s*"' . preg_quote( $name, '/' ) . '"/';
		if ( preg_match( $pattern, $schemaStr, $matches, PREG_OFFSET_CAPTURE ) ) {
			return (float)$matches[0][1];
		}
		return INF;
	}

	private function getGroupBy( $firstKey, $fields, $printoutKey ): ?string {
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
		if ( $this->params['categories'] ) {
			unset( $fields['categories'] );
			return implode( ', ', array_values( $fields ) );
		}
		return null;
	}

	private function parseOrderBy(): array {
		$ret = [];
		foreach ( \VisualData::splitString( $this->params['order'] ) as $v ) {
			preg_match( '/^\s*+(.+?)\s*+(ASC|DESC)?\s*+$/i', $v, $match );
			$ret[ $match[1] ] = $match[2] ?? 'ASC';
		}
		return $ret;
	}

	// ---------------------------------------------------------------------
	// Options
	// ---------------------------------------------------------------------

	private function getOptions( $firstKey, $orderBy, &$fields, &$tables, &$joins ) {
		if ( $this->count || $this->params['count-printout'] || $this->params['count-categories'] ) {
			return [];
		}

		$options = [];
		$optionsMap = [ 'order' => 'ORDER BY', 'limit' => 'LIMIT', 'offset' => 'OFFSET' ];
		$specialPrefix = false;

		foreach ( $optionsMap as $key => $sqlKeyword ) {
			if ( empty( $this->params[ $key ] ) ) {
				continue;
			}

			switch ( $key ) {
				case 'order':
					[ $orderSQL, $specialPrefix ] = $this->buildOrderClause( $orderBy, $fields );
					if ( $orderSQL !== '' ) {
						$options[ $sqlKeyword ] = $orderSQL;
					}
					break;

				case 'limit':
				case 'offset':
					if ( preg_match( '/^\s*\d+\s*$/', $this->params[ $key ] ) ) {
						$options[ $sqlKeyword ] = (int)$this->params[ $key ];
					}
					break;
			}
		}

		if ( $specialPrefix ) {
			$this->addSpecialPrefixSortJoin( $firstKey, $specialPrefix, $tables, $joins );
		}

		return $options;
	}

	private function buildOrderClause( array $orderBy, array &$fields ): array {
		$parts = [];
		$specialPrefix = false;

		foreach ( $orderBy as $printout => $sort ) {
			$index = array_search( $printout, $this->mapKeyToPrintout );
			if ( $index !== false ) {
				$parts[] = "v$index $sort";
				if ( $this->treeFormat ) {
					$fields[ "v$index" ] = "t$index.value";
				}
				continue;
			}

			if ( in_array( $printout, ResultPrinter::$titleAliases ) ) {
				$parts[] = "page_title $sort";
				continue;
			}

			if ( in_array( $printout, $this->specialPrefixes ) ) {
				$parts[] = "rev_sort.timestamp $sort";
				$specialPrefix = $printout;
			}
		}

		return [ implode( ', ', $parts ), $specialPrefix ];
	}

	private function addSpecialPrefixSortJoin( $firstKey, $specialPrefix, array &$tables, array &$joins ): void {
		$orderFunc = $this->getSpecialPrefixOrderFunc( $specialPrefix );

		$subquery = $this->dbr->buildSelectSubquery(
			[ 'revision' ],
			[ 'rev_page', 'timestamp' => "$orderFunc(rev_timestamp)" ],
			[],
			__METHOD__,
			[ 'GROUP BY' => 'rev_page' ]
		);

		$tables['rev_sort'] = $subquery;
		$joins['rev_sort'] = [ 'JOIN', [ "rev_sort.rev_page = t$firstKey.page_id" ] ];
	}

	// ---------------------------------------------------------------------
	// Query execution
	// ---------------------------------------------------------------------

	private function performQuery() {
		if ( count( $this->errors ) ) {
			return;
		}

		$items = $this->buildItems();
		if ( $items === null ) {
			return;
		}

		$this->computeItemsUseHaving( $items );

		usort( $items, static function ( $a, $b ) {
			$aVal = $a['depth'] + (int)$a['isArray'];
			$bVal = $b['depth'] + (int)$b['isArray'];
			return ( $aVal == $bVal ? 0 : (int)( $aVal > $bVal ) );
		} );

		$this->resolveHierarchy( $items );

		$secondaryPrintouts = $this->trimSecondaryPrintouts( $items );

		[ $fields, $tables, $conds, $joins, $having, $mapConds, $firstKey ] =
			$this->buildQueryTables( $items );

		$this->getConditions( $firstKey, $mapConds, $conds, $tables, $joins, $fields, $having );

		$orderBy = $this->parseOrderBy();
		$options = $this->getOptions( $firstKey, $orderBy, $fields, $tables, $joins );

		$tables['page_alias'] = 'page';
		$joins['page_alias'] = [ 'JOIN', [ "page_alias.page_id = t$firstKey.page_id" ] ];

		$printoutKey = $this->applyCountFields( $items, $fields, $options );

		if ( count( $having ) ) {
			$options['HAVING'] = $having;
		}

		$groupBy = $this->getGroupBy( $firstKey, $fields, $printoutKey );
		if ( !empty( $groupBy ) ) {
			$options['GROUP BY'] = $groupBy;
		}

		if ( $this->count ) {
			$fields = [
				'count' => $this->treeFormat
					? "COUNT( DISTINCT ( t$firstKey.page_id ) )"
					: 'COUNT(*)',
			];
		}

		if ( count( $tables ) > 61 ) {
			$this->errors[] = 'Too many tables in the sql, try to specify at least one valid printout or less printouts';
			return;
		}

		$res = $this->runQuery( $tables, $fields, $conds, $options, $joins );
		if ( $res === null ) {
			return;
		}

		if ( $this->collectResult( $res, $printoutKey ) ) {
			return;
		}

		$this->collectRows( $res, $secondaryPrintouts );
	}

	private function buildItems(): ?array {
		$arr = [];
		foreach ( $this->conditionProperties as $pathNoIndex ) {
			$arr[ $pathNoIndex ] = 0;
		}
		foreach ( $this->printouts as $pathNoIndex ) {
			$arr[ $pathNoIndex ] = 1;
		}

		$thisClass = $this;
		uksort( $arr, static function ( $ka, $kb ) use ( $thisClass ) {
			return substr_count( $ka, $thisClass->sep ) <=> substr_count( $kb, $thisClass->sep );
		} );

		$this->conditionProperties = array_intersect(
			$this->conditionProperties,
			array_keys( $this->mapPathNoIndexTable )
		);

		if ( !$this->conditionId && !count( $this->conditionProperties ) && !count( $this->conditionSubjects ) ) {
			$this->errors[] = 'no valid conditions';
			return null;
		}

		$arr = [];
		foreach ( $this->conditionProperties as $pathNoIndex ) {
			$arr[ $pathNoIndex ] = false;
		}
		foreach ( $this->printouts as $pathNoIndex ) {
			if ( array_key_exists( $pathNoIndex, $this->mapPathNoIndexTable ) ) {
				$arr[ $pathNoIndex ] = true;
			}
		}

		$orderBy = $this->parseOrderBy();
		foreach ( $orderBy as $printout => $sort ) {
			if ( !array_key_exists( $printout, $arr )
				&& array_key_exists( $printout, $this->mapPathNoIndexTable )
			) {
				$arr[ $printout ] = false;
			}
		}

		if ( !count( $arr ) ) {
			if ( !$this->params['count-categories'] ) {
				$this->errors[] = 'no valid printouts';
				return null;
			}

			$this->printouts = $this->getAllOrderedPrintouts();
			if ( !count( $this->printouts ) ) {
				$this->errors[] = 'no valid printouts';
				return null;
			}
			$arr[ $this->printouts[0] ] = false;
		}

		$items = [];
		foreach ( $arr as $pathNoIndex => $isPrintout ) {
			$items[] = [
				'printout' => $pathNoIndex,
				'printoutParent' => substr( $pathNoIndex, 0, strrpos( $pathNoIndex, $this->sep ) ),
				'depth' => substr_count( $pathNoIndex, $this->sep ),
				'isArray' => $this->parentSchemaIsArray( $pathNoIndex ),
				'isPrintout' => $isPrintout,
				'isCondition' => in_array( $pathNoIndex, $this->conditionProperties ),
				'isOrderBy' => array_key_exists( $pathNoIndex, $orderBy ),
			];
		}

		return $items;
	}

	private function parentSchemaIsArray( string $printout ): bool {
		if ( array_key_exists( $printout, $this->mapPrintoutParentSchemas ) ) {
			return $this->mapPrintoutParentSchemas[ $printout ];
		}

		$cleanPath = $printout;

		$dot = strrpos( $cleanPath, $this->sep );
		$parentCleanPath = $dot === false ? '' : substr( $cleanPath, 0, $dot );

		$index = $this->schemaIndex();
		$isArray = $parentCleanPath !== ''
			&& ( $index[ $parentCleanPath ]['type'] ?? null ) === 'array';

		$this->mapPrintoutParentSchemas[ $printout ] = $isArray;
		return $isArray;
	}

	private function computeItemsUseHaving( array $items ): void {
		$this->conditionsUseHaving = [];

		if ( !$this->treeFormat || $this->count ) {
			return;
		}

		$maxDepthCondition = 0;
		foreach ( $items as $v ) {
			if ( $v['isCondition'] && $v['depth'] > $maxDepthCondition ) {
				$maxDepthCondition = $v['depth'];
			}
		}

		foreach ( $this->AndConditions as $i => $value ) {
			$allLikes = true;
			$multipleItems = false;

			foreach ( $value as $printout => $values ) {
				foreach ( $values as $v ) {
					$comparator = $this->conditionMeta[ $printout ][ $v ]['comparator'] ?? '=';
					if ( !in_array( $comparator, self::LIKE_COMPARATORS, true ) ) {
						$allLikes = false;
						break 2;
					}
				}

				if ( !$allLikes || $multipleItems ) {
					continue;
				}

				foreach ( $items as $v ) {
					if ( $v['printout'] === $printout && $v['depth'] === $maxDepthCondition ) {
						if ( $this->parentSchemaIsArray( $v['printout'] ) ) {
							$multipleItems = true;
							break;
						}
					}
				}
			}

			if ( $allLikes && $multipleItems ) {
				$this->conditionsUseHaving[] = $i;
			}
		}
	}

	private function resolveHierarchy( array &$items ): void {
		$printoutToIndex = [];
		foreach ( $items as $index => $item ) {
			$printoutToIndex[ $item['printout'] ] = $index;
		}

		foreach ( $items as $i => &$item ) {
			if ( !$item['isArray'] ) {
				for ( $j = 0; $j < $i; $j++ ) {
					if ( empty( $items[ $j ]['printoutParent'] ) || $items[ $j ]['isArray'] ) {
						continue;
					}
					if ( strpos( $item['printoutParent'], $items[ $j ]['printoutParent'] . $this->sep ) === 0 ) {
						$item['parentIndex'] = $j;
						break;
					}
				}

				for ( $j = 0; $j < $i; $j++ ) {
					if ( $items[ $j ]['isArray'] ) {
						continue;
					}
					if ( $item['printoutParent'] === $items[ $j ]['printoutParent'] ) {
						$item['topSiblingIndex'] = $j;
						break;
					}
				}
			} else {
				for ( $j = 0; $j < $i; $j++ ) {
					if ( $items[ $j ]['isArray'] ) {
						continue;
					}
					if ( empty( $items[ $j ]['printoutParent'] ) ) {
						continue;
					}
					if ( $item['printoutParent'] === $items[ $j ]['printoutParent'] ) {
						$item['parentIndex'] = $j;
						break;
					}
				}
			}
		}
		unset( $item );
	}

	private function trimSecondaryPrintouts( array &$items ): array {
		if ( !$this->treeFormat || $this->conditionId ) {
			return [];
		}
		if ( count( $items ) <= $GLOBALS['wgVisualDataQueryProcessorPrintoutsLimit'] ) {
			return [];
		}

		$printoutToIndex = [];
		foreach ( $items as $index => $item ) {
			$printoutToIndex[ $item['printout'] ] = $index;
		}

		$parents = [];
		foreach ( $items as $item ) {
			if ( isset( $printoutToIndex[ $item['printoutParent'] ] ) ) {
				$parents[] = $item['printoutParent'];
			}
		}

		$secondary = [];
		foreach ( $items as $i => $v ) {
			if ( !$v['isCondition'] && !$v['isOrderBy'] && !in_array( $i, $parents ) ) {
				$secondary[] = $v['printout'];
			}
		}

		foreach ( array_reverse( $items, true ) as $i => $v ) {
			if ( in_array( $v['printout'], $secondary ) ) {
				if ( count( $items ) > 1 ) {
					unset( $items[ $i ] );
				} else {
					unset( $secondary[ array_search( $v['printout'], $secondary ) ] );
				}
			}
		}

		return $secondary;
	}

	private function buildQueryTables( array $items ): array {
		$firstKey = array_key_first( $items );

		$fields = [ 'page_id' => "t$firstKey.page_id" ];
		$tables = [];
		$conds = [];
		$joins = [];
		$having = [];
		$mapConds = [];

		if ( $this->conditionId ) {
			$conds[] = "t$firstKey.page_id = " . $this->conditionId;
		}

		foreach ( $items as $key => $v ) {
			$pathNoIndex = $v['printout'];
			$this->mapKeyToPrintout[ $key ] = $v['printout'];

			$tablename = $this->mapPathNoIndexTable[ $pathNoIndex ];
			$mapConds[ $pathNoIndex ] = [ 'key' => $key, 'tablename' => $tablename ];

			$conds_ = [
				'p.schema_id' => $this->schemaId,
				'p.cleanpath' => $pathNoIndex,
			];

			if ( $key > $firstKey ) {
				$joinConds = [ "t$key.page_id=t$firstKey.page_id" ];
				if ( $this->params['hierarchical-conditions'] ) {
					$parentFallback = "'" . addslashes( $v['printoutParent'] ) . "'";
					if ( isset( $v['topSiblingIndex'] ) ) {
						$parentIndex = $v['topSiblingIndex'];
						$joinConds[] = "t$key.path_parent = COALESCE( t$parentIndex.path_parent, $parentFallback )";
					} elseif ( isset( $v['parentIndex'] ) ) {
						$parentIndex = $v['parentIndex'];
						$joinConds[] = "LOCATE( COALESCE( t$parentIndex.path_parent, $parentFallback ), t$key.path_parent ) = 1";
					}
				}
				$joins[ "t$key" ] = [ 'LEFT JOIN', $this->dbr->makeList( $joinConds, LIST_AND ) ];
			} elseif ( $this->conditionId ) {
				$conds_[] = 't.page_id = ' . $this->conditionId;
			}

			$tables[ "t$key" ] = $this->dbr->buildSelectSubquery(
				[
					't' => "visualdata_$tablename",
					'p' => 'visualdata_props',
				],
				[ 't.value', 't.page_id', 'p.cleanpath', 'p.jsonpath', 'p.path', 'p.path_parent', 'p.schema_id' ],
				$conds_,
				__METHOD__,
				[],
				[ 'p' => [ 'JOIN', 'p.id=t.prop_id' ] ]
			);

			if ( $v['isPrintout'] ) {
				if ( !$this->treeFormat ) {
					$fields[ "v$key" ] = "t$key.value";
					$fields[ "j$key" ] = "t$key.jsonpath";
				} else {
					$fields[ "p$key" ] = "GROUP_CONCAT(t$key.path " . self::GROUP_CONCAT_SEPARATOR . ")";
					$fields[ "c$key" ] = "GROUP_CONCAT(t$key.value " . self::GROUP_CONCAT_SEPARATOR . ")";
					$fields[ "j$key" ] = "GROUP_CONCAT(t$key.jsonpath " . self::GROUP_CONCAT_SEPARATOR . ")";
				}
			}

			if ( $this->treeFormat && $v['isCondition'] ) {
				$fields[ "c$key" ] = "GROUP_CONCAT(t$key.value " . self::GROUP_CONCAT_SEPARATOR . ")";
			}
		}

		return [ $fields, $tables, $conds, $joins, $having, $mapConds, $firstKey ];
	}

	private function applyCountFields( array $items, array &$fields, array &$options ): ?string {
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

		return $printoutKey;
	}

	private function runQuery( array $tables, array $fields, array $conds, array $options, array $joins ) {
		$method = !$this->debug
			? ( $this->count ? 'selectField' : 'select' )
			: 'selectSQLText';

		$res = $this->dbr->$method(
			$tables,
			$fields,
			$conds,
			__METHOD__,
			$options,
			$joins
		);

		if ( $this->debug ) {
			$this->result = (string)$res;
			return null;
		}

		return $res;
	}

	private function collectResult( $res, ?string $printoutKey ): bool {
		if ( $this->params['count-printout'] ) {
			$ret = [];
			foreach ( $res as $row ) {
				$row = (array)$row;
				$ret[ $row[ $printoutKey ] ] = (int)$row['count'];
			}
			ksort( $ret );
			$this->result = $ret;
			return true;
		}

		if ( $this->params['count-categories'] ) {
			$ret = [];
			foreach ( $res as $row ) {
				$row = (array)$row;
				$ret[ $row['categories'] ] = $row['count'];
			}
			$this->result = $ret;
			return true;
		}

		if ( $this->count ) {
			$this->result = (int)$res;
			return true;
		}

		return false;
	}

	private function collectRows( $res, array $secondaryPrintouts ): void {
		$this->result = [];
		if ( !$res->numRows() ) {
			return;
		}

		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		$separator = chr( hexdec( '0x1E' ) );
		$titles = [];
		$badTitle = \SpecialPage::getTitleFor( 'Badtitle' );

		foreach ( $res as $row ) {
			$row = (array)$row;
			$row_ = [];
			$categories = [];
			$pageId = $row['page_id'];
			unset( $row['page_id'] );

			$metadata = [
				'pathToCleanpath' => [],
				'pathToJsonPath' => []
			];

			if ( !array_key_exists( $pageId, $titles ) ) {
				$title = TitleClass::newFromID( $pageId );
				$titles[ $pageId ] = [ $title, $permissionManager->userCan( 'read', $this->user, $title ) ];
			}
			[ $title, $canRead ] = $titles[ $pageId ];

			if ( !$canRead ) {
				$this->result[] = [
					'title' => $badTitle,
					'data' => array_fill_keys( $this->printoutsOriginal, '' ),
					'categories' => [],
					'metadata' => $metadata
				];
				continue;
			}

			if ( !empty( $row['categories'] ) ) {
				$categories = array_values( array_unique(
					explode( $separator, str_replace( '_', ' ', $row['categories'] ) )
				) );
			}
			unset( $row['categories'] );

			if ( !$this->treeFormat ) {
				$row_ = array_fill_keys( $this->printoutsOriginal, '' );
				foreach ( $row as $k => $v ) {
					$index = substr( $k, 1 );
					$printout = $this->mapKeyToPrintout[ $index ];
					$row_[ $printout ] = $this->castType( $v, $printout );
					$metadata['pathToCleanpath'][ $row[ "p$index" ] ] = $printout;
					$metadata['pathToJsonPath'][ $row[ "p$index" ] ] = $row[ "j$index" ];
				}
			} else {
				foreach ( $this->mapKeyToPrintout as $key => $printout ) {
					if ( empty( $row[ "p$key" ] ) ) {
						continue;
					}
					$paths = explode( $separator, $row[ "p$key" ] );
					$values = array_slice( explode( $separator, (string)$row[ "c$key" ] ), 0, count( $paths ) );
					$paths = array_slice( $paths, 0, count( $values ) );
					$jsonpaths = explode( $separator, $row[ "j$key" ] );
					$jsonpaths = array_slice( $jsonpaths, 0, count( $values ) );
					foreach ( $paths as $key_ => $path ) {
						$row_[ $path ] = $this->castType( $values[ $key_ ], $printout );
						$metadata['pathToCleanpath'][ $path ] = $printout;
						$metadata['pathToJsonPath'][ $path ] = $jsonpaths[ $key_ ];
					}
				}

				if ( $this->params['secondary-printouts'] ) {
					// @TODO refactor if needed
					$this->result = [ $row_, $metadata ];
					continue;
				}

				if ( count( $secondaryPrintouts ) ) {
					$params_ = array_merge( $this->params, $this->paramsForSecondary() );
					$params_['query'] = $pageId;
					$params_['printouts'] = $secondaryPrintouts;
					$processor = new QueryProcessor(
						$this->user,
						$this->schemaMetadata,
						$params_,
					);
					$method = $this->treeFormat ? 'getResultsTree' : 'getResults';
					$res_ = $processor->$method();
					[ $data_, $metadata_ ] = $processor->$method() + [ null, null ];
					if ( $data_ ) {
						$row_ = array_merge( $row_, $data_ );
					}
					if ( $metadata_ ) {
						$metadata = \JsonForms::arrayMergeRecursive( $metadata, $metadata_ );
					}
				}
			}

			$this->result[] = [
				'title' => $title,
				'data' => SchemaUtils::flatToNested( $row_, $this->sep ),
				'categories' => $categories,
				'metadata' => $metadata
			];
		}
	}

	private function paramsForSecondary(): array {
		return [
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
			'secondary-printouts' => true,
		];
	}

	/**
	 * Cast a single value based on the storage type. Inlined SchemaProcessor::castType.
	 */
	private function castType( $value, string $printout ) {
		$tablename = $this->mapPathNoIndexTable[ $printout ] ?? 'text';

		switch ( $tablename ) {
			case 'integer':
				return (int)$value;
			case 'numeric':
				return (float)$value;
			case 'boolean':
				return (bool)$value;
			case 'date':
			case 'datetime':
			case 'time':
			case 'text':
			case 'textarea':
			default:
				return $value;
		}
	}
}

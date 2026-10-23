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
 * @copyright Copyright ©2026, https://wikisphere.org
 */

namespace MediaWiki\Extension\VisualData;

use MediaWiki\Extension\VisualData\Aliases\Title as TitleClass;

class DatabaseManager {

	/** @var dbr */
	public $dbr;

	/** @var dbw */
	public $dbw;

	/** @var int */
	public $articleId;

	/** @var dateTime */
	public $dateTime;

	/** @var array */
	private $mapSchemaPathPropId = [];

	/** @var array */
	private $errors = [];

	/** @var array */
	public static $propTypes = [
		'text',
		'textarea',
		'date',
		'datetime',
		'time',
		'integer',
		'numeric',
		'boolean'
	];

	/** @var array */
	public static $tables = [
		'visualdata_schema_pages',
		'visualdata_schemas',
		'visualdata_props',
		// 'visualdata_printouts',
		'visualdata_prop_tables',
		'visualdata_links',
		'visualdata_links_template',
		'visualdata_text',
		'visualdata_textarea',
		'visualdata_date',
		'visualdata_datetime',
		'visualdata_time',
		'visualdata_integer',
		'visualdata_numeric',
		'visualdata_boolean'
	];

	public function __construct() {
		$this->dbr = \JsonForms::getDB( DB_REPLICA );
		$this->dbw = \JsonForms::getDB( DB_PRIMARY );
		$this->dateTime = date( 'Y-m-d H:i:s' );
	}

	/**
	 * @param string $schemaName
	 * @return int
	 */
	private function recordSchema( $schemaName ) {
		$tableName = 'visualdata_schemas';
		$conds = [
			'name' => $schemaName,
		];
		$insert = array_merge( $conds, [
			'updated_at' => $this->dateTime,
			'created_at' => $this->dateTime
		] );
		$schemaId = $this->updateOrInsert( $tableName, $conds, $insert );

		return $schemaId;
	}

	/**
	 * @param string $tableName
	 * @param array $conds
	 * @param array $insert
	 * @param array|null $update
	 * @return int
	 */
	private function updateOrInsert( $tableName, $conds, $insert, $update = null ) {
		// @TODO use upsert
		$id = $this->dbr->selectField(
			$tableName,
			'id',
			$conds,
			__METHOD__,
			[ 'LIMIT' => 1 ]
		);

		if ( !$id ) {
			$res = $this->dbw->insert(
				$tableName,
				$insert
			);
			// @TODO use `mysql> select last_insert_id();`
			$id = $this->dbr->selectField(
				$tableName,
				'id',
				$conds,
				__METHOD__,
				[ 'ORDER BY' => 'id DESC' ]
			);
		}

		if ( !$update || !count( $update ) ) {
			return $id;
		}

		$res = $this->dbw->update(
			$tableName,
			$update,
			[ 'id' => $id ],
			__METHOD__
		);

		return $id;
	}

	/**
	 * @param string $schemaName
	 * @param int $articleId
	 * @return int
	 */
	private function recordSchemaPage( $schemaName, $articleId ) {
		$schemaId = $this->recordSchema( $schemaName );

		$tableName = 'visualdata_schema_pages';
		$conds = [
			'page_id' => $articleId,
			'schema_id' => $schemaId,
		];
		$insert = array_merge( $conds, [
			'updated_at' => $this->dateTime,
			'created_at' => $this->dateTime
		] );
		$this->updateOrInsert( $tableName, $conds, $insert );

		return $schemaId;
	}

	/**
	 * @param array $schema
	 * @return string
	 */
	public function schemaToPropType( $schema ) {
		$getType = static function () use ( $schema ) {
			$format = null;

			if ( !empty( $schema['x-format'] ) ) {
				$format = $schema['x-format'];

			} elseif ( !empty( $schema['format'] ) ) {
				$format = $schema['format'];
			}

			if ( is_string( $format ) && in_array( $format, self::$propTypes, true ) ) {
				return $format;
			}

			if ( !empty( $schema['type'] ) && is_string( $schema['type'] ) ) {
				return $schema['type'];
			}

			return 'string';
		};

		$ret = $getType();
		if ( $ret === 'string' ) {
			return 'text';
		}

		return $ret;
	}

	/**
	 * @param string $type
	 * @return int
	 */
	public function tableTypeToId( $type ) {
		switch ( $type ) {
			case 'text':
				return 1;
			case 'textarea':
				return 2;
			case 'date':
				return 3;
			case 'datetime':
				return 4;
			case 'time':
				return 5;
			case 'integer':
				return 6;
			case 'numeric':
				return 7;
			case 'boolean':
				return 8;
		}

		return 0;
	}

	/**
	 * @param string $schemaName
	 * @param Title|MediaWiki\Title\Title $title
	 * @param stdClass $structuredValue
	 * @param array &$errors
	 * @return int
	 */
	public function recordProperties( $schemaName, $title, $structuredValue, &$errors ) {
		$articleId = $title->getArticleID();
		$schemaId = $this->recordSchemaPage( $schemaName, $articleId );

		// delete all values related to this schema and article
		foreach ( self::$propTypes as $propType ) {
			$tableName = "visualdata_$propType";

			$this->dbw->deleteJoin(
				// delTable
				$tableName,
				// joinTable
				'visualdata_props',
				// delVar
				'prop_id',
				// joinVar
				$this->dbr->tableName( 'visualdata_props' ) . '.id',
				// conds
				[ 'page_id' => $articleId, 'schema_id' => $schemaId ],
				__METHOD__
			);
		}

/*
[structuredValue] => stdClass Object
        (
            [values] => stdClass Object
                (
                    [fullname] => stdClass Object
                        (
                            [value] => aaa
                            [key] => fullname
                        )

                    [name.first_name] => stdClass Object
                        (
                            [value] => aaa
                            [key] => first_name
                        )

*/
		$rows = [];
		$mapPathPropType = [];
		$mapCleanPathTableId = [];
		$mapPathValue = [];
		foreach ( $structuredValue->values as $path => $value ) {
			$cleanPath = $structuredValue->cleanPaths->{$path};
			$jsonPath = $structuredValue->jsonPaths->{$path};
			$schema = $structuredValue->schemas->{$jsonPath};

			$propType = $this->schemaToPropType( (array)$schema );
			$table_id = $this->tableTypeToId( $propType );

			// invalid type, must be scalar
			if ( !$table_id ) {
				continue;
			}

			$mapCleanPathTableId[$cleanPath] = $table_id;
			$mapPathPropType[$path] = $propType;
			$mapPathValue[$path] = $value->value;

			$pathParent = substr( $path, 0, strrpos( $path, '/' ) );

			$rows[] = [
				'schema_id' => $schemaId,
				'table_id' => $table_id,
				'path' => $path,
				'cleanpath' => $cleanPath,
				'jsonpath' => $jsonPath,
				'path_parent' => $pathParent,
				'updated_at' => $this->dateTime,
				'created_at' => $this->dateTime,
			];
		}

		// insert new props
		$tableName = 'visualdata_props';
		$options = [ 'IGNORE' ];
		$res = $this->dbw->insert(
			$tableName,
			$rows,
			__METHOD__,
			$options
		);

		// get props IDs
		$conds = [ 'schema_id' => $schemaId ];
		$options = [];
		$joins = [];
		$tables = [ $tableName ];
		$res = $this->dbr->select(
			// tables
			$tables,
			// fields
			[ 'id', 'path' ],
			// where
			$conds,
			__METHOD__,
			// options
			$options,
			// join
			$joins
		);

		$mapPathPropId = [];
		foreach ( $res as $row ) {
			$mapPathPropId[$row->path] = $row->id;
		}

		$rows_ = [];
		foreach ( $mapCleanPathTableId as $cleanPath => $table_id ) {
			$rows_[] = [
				'schema_id' => $schemaId,
				'table_id' => $table_id,
				'cleanpath' => $cleanPath,
				'updated_at' => $this->dateTime,
				'created_at' => $this->dateTime,
			];
		}

		$options = [ 'IGNORE' ];
		$tableName = 'visualdata_prop_tables';
		$res = $this->dbw->insert(
			$tableName,
			$rows_,
			__METHOD__,
			$options
		);

		$tables = [];
		foreach ( $rows as $value ) {
			$path = $value['path'];
			$propType = $mapPathPropType[$path];
			$propId = $mapPathPropId[$path];
			$value_ = $mapPathValue[$path];
			$tables[$propType][] = [
				'page_id' => $articleId,
				'prop_id' => $propId,
				'value' => $value_,
				'created_at' => $this->dateTime,
			];
		}
trigger_error('^^tables ' . print_r($tables,1) );
		$ret = 0;
		foreach ( $tables as $propType => $rows ) {
			$tableName = "visualdata_$propType";
			$res = $this->dbw->insert( $tableName, $rows );
			$ret += count( $rows );
		}

		return $ret;
	}
}

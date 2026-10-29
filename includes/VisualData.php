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

use MediaWiki\Extension\JsonForms\ParametersProcessor;
use MediaWiki\Extension\VisualData\Aliases\Html as HtmlClass;
use MediaWiki\Extension\VisualData\Aliases\Linker as LinkerClass;
use MediaWiki\Extension\VisualData\Aliases\Title as TitleClass;
use MediaWiki\Extension\VisualData\QueryProcessor;

class VisualData {

	/**
	 * @return void
	 */
	public static function initialize() {
		self::$Logger = LoggerFactory::getInstance( 'VisualData' );
	}

	/**
	 * @param Parser $parser
	 * @param mixed ...$argv
	 * @return array
	 */
	public static function parserFunctionPrint( Parser $parser, ...$argv ) {
		$parserOutput = $parser->getOutput();
		
	}

	/**
	 * @param Parser $parser
	 * @param mixed ...$argv
	 * @return array
	 */
	public static function parserFunctionQuery( Parser $parser, ...$argv ) {
		$parserOutput = $parser->getOutput();
		// $parserOutput->setExtensionData( 'jsonforms', true );

		$context = RequestContext::getMain();
		$output = $context->getOutput();
		$user = $context->getUser();

		$returnError = static function ( $error ) {
			return [
				$error,
				'isHTML' => false
			];
		};

		$functionReturn = static function ( $value ) {
			return [ $value, 'noparse' => true, 'isHTML' => true ];
		};

		if ( empty( $argv[0] ) ) {
			return $functionReturn(
				self::printError(
					$parserOutput,
					'visualdata-parserfunction-error-no-query-name',
				),
			);
		}

		$queryName = $argv[0];
		$data = [];
		$errorMessage = null;

		$formSchema = \JsonForms::getSourceSchema(
			'VisualData/AskQuery',
			'JsonSchema',
		);

		if ( !$formSchema ) {
			throw new MWException( 'Cannot load core schema' );
		}

		$parametersProcessor = new ParametersProcessor( $argv, $formSchema );
		$parametersProcessor->buildOptionsSchema();

		// default options merged with inline options
		$allParameters = $parametersProcessor->getOptions();

		$formDescriptor = \JsonForms::getSourceSchema( $queryName, 'VisualDataQuery' );

		if ( !$formDescriptor ) {
			return $functionReturn(
				self::printError(
					$parserOutput,
					'jsonforms-parserfunction-error-no-form',
				),
			);
		}

		$formDescriptor = $parametersProcessor->mergeFormDescriptor( $formDescriptor );

		if ( empty( $formDescriptor->schema ) ) {
			return $functionReturn(
				self::printError(
					$parserOutput,
					'jsonforms-parserfunction-error-no-schema',
				),
			);
		}

		$params = json_decode( json_encode( $formDescriptor ), 2 );

		$errors_ = [];
		$resultPrinter = self::prepareResults(
			$parser,
			$context,
			$params,
			$errors_
		);

		if ( !$resultPrinter ) {
			return $returnError( $errors_[0] );
		}

		$results = $resultPrinter->getResults();
		
		$isHtml = $resultPrinter->isHtml();

		if ( count( $resultPrinter->getModules() ) ) {
			$parserOutput->addModules( $resultPrinter->getModules() );
		}

		return [ $results, 'isHTML' => $isHtml ];
	}

	/**
	 * @param Parser $parser
	 * @param Context $context
	 * @param array $params
	 * @param array &$errors = []
	 * @return bool|ResultPrinter
	 */
	public static function prepareResults(
		$parser,
		$context,
		$params,
		&$errors = []
	) {
	
	
/*
[conditions] => Array
                (
                    [0] => stdClass Object
                        (
                            [property] => fullname
                            [comparator] => +
                            [types] => Array
                                (
                                    [0] => string
                                )

                            [types_string] => string
                        )

                )
                
 OR
 
 	"conditions": [
			{
				"or": [
					{
						"property": "fullname",
						"comparator": "=",
						"types": [
							"string"
						],
						"types_string": "string",
						"value": "cccd"
					},
					{
						"property": "fullname",
						"comparator": "=",
						"types": [
							"string"
						],
						"types_string": "string",
						"value": "x"
					}
				]
			}
		]
		
                
            */
		if ( empty( $params['schema'] ) ) {
			$errors[] = 'no schema';
			return false;
		}

		if ( empty( $params['format'] ) ) {
			$errors[] = 'no format';
			return false;
		}

		if ( !$params['schema'] ) {
			$errors[] = 'no valid schema';
			return false;
		}

		$title = TitleClass::newFromText( 'JsonSchema:' . $params['schema'] );
		$wikiPage = \JsonForms::getWikiPage( $title );

		$schemaMetadata = [];
		if ( $wikiPage ) {
			$metadata = \JsonForms::getMetadata( $wikiPage );
			if ( $metadata ) {
				$schemaMetadata = json_decode( json_encode( $metadata ), true );
			}
		}

		if ( !array_key_exists( $params['format'], $GLOBALS['wgVisualDataResultPrinterClasses'] ) ) {
			$errors[] = 'no valid format';
			return false;
		}

		$params['format'] = 'template';
		$className = $GLOBALS['wgVisualDataResultPrinterClasses'][$params['format']];
		$class = "MediaWiki\Extension\VisualData\ResultPrinters\\{$className}";

		// parse format-related parameters
		// if ( property_exists( $class, 'parameters' ) ) {
		// 	$paramsSchema_ = $class::$parameters;
		// 	$params_ = ParametersProcessor::applySchemaDefaults( $params, $paramsSchema_ );
		// }

		$user = $context->getUser();
		$queryProcessor = new QueryProcessor( $user, $schemaMetadata, $params );

		// $res = $queryProcessor->getResultsTree();
		// print_r($res);
		// exit;
		return new $class( $parser, $user, $context->getOutput(), $queryProcessor, $params );
	}

	/**
	 * @param string $str
	 * @param string $token
	 * @return array
	 */
	public static function splitString( $str, $token = ',' ) {
		$ret = [];
		$tokenLen = strlen( $token );
		$start = 0;

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( ( $pos = strpos( $str, $token, $start ) ) !== false ) {
			$segment = substr( $str, $start, $pos - $start );
			$val = trim( $segment );
			if ( $val !== '' ) {
				$ret[] = $val;
			}
			$start = $pos + $tokenLen;
		}

		$segment = substr( $str, $start );
		$val = trim( $segment );
		if ( $val !== '' ) {
			$ret[] = $val;
		}

		return $ret;
	}

	/**
	 * @param string &$name
	 */
	public static function adjustSchemaName( &$name ) {
		$name = str_replace( '_', ' ', (string)$name );
	}

}

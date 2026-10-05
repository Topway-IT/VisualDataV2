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

		$formSchema = JsonForms::getSourceSchema(
			'VisualData/AskQuery',
			'JsonSchema',
		);

		if ( !$formSchema ) {
			throw new MWException( 'Cannot load core schema' );
		}

		$formSchemaPopupConfig = self::getSourceSchema(
			'ButtonWidgetSchema',
			'JsonSchema/Core',
		);

		$parametersProcessor = new ParametersProcessor( $argv, $formSchema );
		$parametersProcessor->buildOptionsSchema();

		// default options merged with inline options
		$allParameters = $parametersProcessor->getOptions();

		$formDescriptor = self::getSourceSchema( $queryName, 'VisualDataQuery' );

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


print_r($formDescriptor);
exit;

		$result = self::getPageForm( $user, $output, $formDescriptor );

		return [ $result, 'noparse' => true, 'isHTML' => true ];
	}

}

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
 * @copyright Copyright ©2023, https://wikisphere.org
 */

namespace MediaWiki\Extension\VisualData\ResultPrinters;

use MediaWiki\Extension\JsonForms\ParametersProcessor;
use MediaWiki\Extension\JsonForms\TemplateRender;
use MediaWiki\Extension\VisualData\ResultPrinter;

class TemplateResultPrinter extends ResultPrinter {

	/**
	 * @inheritDoc
	 */
	public function isHtml() {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function getResults() {
		$results = $this->queryProcessor->getResultsTree();
		return $this->processResults( $results );
	}

	/**
	 * @param Parser $parser
	 * @param mixed ...$argv
	 * @return array
	 */
	private function initializeTemplateRender() {
		$parser = $this->parser;

		// @see JsonForms::parserFunctionRender
		$formSchema = (object)[
			'type' => 'object',
			'properties' => (object)[
				'template' => (object)[
					'type' => 'string',
				],
				'path' => (object)[
					'type' => 'string',
				],
				'slot' => (object)[
					'type' => 'string',
					'default' => SLOT_ROLE_JSONFORMS_DATA,
				],
				'print_scalar' => (object)[
					'type' => 'bool',
					'default' => false,
				],
			],
		];

		$parametersProcessor = new ParametersProcessor( [], $formSchema );
		$parametersProcessor->buildOptionsSchema( $this->params );
		$allParameters = $parametersProcessor->getOptions();

		return $allParameters;
	}

	/**
	 * @inheritDoc
	 */
	public function processResults( $results ) {
		if ( count( $this->queryProcessorErrors() ) ) {
			return implode( ', ', $this->queryProcessorErrors() );
		}
		if ( $this->params['debug'] ) {
			return $results;
		}

		$allParameters = $this->initializeTemplateRender();
		$schemaMetadata = $this->queryProcessor->getSchemaMetadata();

		$ret = [];
		foreach ( $results as $value ) {
			if ( $value['title']->isSpecial( 'Badtitle' ) ) {
				continue;
			}

			$title_ = $value['title'];
			// $slotMetadata = JsonForms::getMetadata( $wikiPage_, $allParameters['slot'] );
			$templateRender = new TemplateRender( $this->user, $value['title'], $allParameters );

			$templateRender->setSchemaMap( $schemaMetadata['processedSchema'] );
			$templateRender->setJsonPaths( $value['metadata']['pathToJsonPath'] );

			$templateRender->setParser( $this->parser );
			$ret[] = $templateRender->render( $value['data'] );			
		}
		return $this->processRoot( $ret );
	}

}

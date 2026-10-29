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

use MediaWiki\Extension\Scribunto\Engines\LuaStandalone\LuaStandaloneEngine;
use MediaWiki\Extension\VisualData\Aliases\Title as TitleClass;
use MediaWiki\MediaWikiServices;
use MWException;
use Parser;

class ResultPrinter {

	/** @var User */
	protected $user;

	/** @var Output */
	protected $output;

	/** @var string */
	private $format;

	/** @var Parser */
	public $parser;

	/** @var array */
	public $params;

	/** @var QueryProcessor */
	public $queryProcessor;

	/** @var array */
	public $modules = [];

	/**
	 * @param Parser $parser
	 * @param User $user
	 * @param Output $output
	 * @param QueryProcessor $queryProcessor
	 * @param array $params
	 */
	public function __construct( $parser, $user, $output, $queryProcessor, $params ) {
		$this->queryProcessor = $queryProcessor;
		$this->user = $user;
		$this->parser = $parser;
		$this->output = $output;
		$this->format = $params['format'];
		$this->params = $params;
	}

	/**
	 * @return string
	 */
	public function getResults() {
		$results = $this->queryProcessor->getResults();
		if ( count( $this->queryProcessorErrors() ) ) {
			return implode( ', ', $this->queryProcessorErrors() );
		}
		if ( $this->params['debug'] ) {
			return $results;
		}
		return $this->processResults( $results );
	}

	/**
	 * @credits freephile
	 * Remove parser-only edit section markers from rendered HTML fragments.
	 *
	 * @param string $value
	 * @return string
	 */
	protected function stripParsoidEditSectionMarkers( $value ) {
		if ( strpos( $value, '<mw:editsection' ) === false ) {
			return $value;
		}

		$value = preg_replace(
			'#<mw:editsection\b[^>]*>.*?</mw:editsection>#is',
			'',
			$value
		);
		$value = preg_replace(
			'#<mw:editsection\b[^>]*/\s*>#is',
			'',
			$value
		);

		return $value;
	}

	/**
	 * @param array $result
	 * @return array
	 */
	public function processResults( $result ) {
		if ( !count( $result ) ) {
			return [];
		}
		return ( !$this->queryProcessor->isPrintCondition() ? $result : $result[0] );
	}

	/**
	 * @param array $rows
	 * @return string
	 */
	public function processRoot( $rows ) {
		return implode( '', $rows );
	}

	/**
	 * @return array
	 */
	public function getValidPrintouts() {
		return $this->queryProcessor->getValidPrintouts();
	}

	/**
	 * @return array
	 */
	public function queryProcessorErrors() {
		return $this->queryProcessor->getErrors();
	}

	/**
	 * @return int
	 */
	public function getCount() {
		return $this->queryProcessor->getCount();
	}

	/**
	 * @return array
	 */
	public function getModules() {
		return $this->modules;
	}

	/**
	 * @return bool
	 */
	public function isHtml() {
		return true;
	}

}

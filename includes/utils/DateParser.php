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
 * @copyright Copyright ©2025, https://wikisphere.org
 */

namespace MediaWiki\Extension\VisualData\Utils;

class DateParser {

	/** @var string */
	public $dateStr;

	/**
	 * @param string $dateStr
	 */
	public function __construct( $dateStr ) {
		$this->dateStr = $dateStr;
	}

	/**
	 * @return false|int
	 */
	public function parse() {
		if ( $this->isZeroDate() ) {
			return false;
		}

		$timestamp = strtotime( $this->dateStr );
		if ( $timestamp ) {
			return $timestamp;
		}

		// Sat, 22 Feb 2025 19:07:38 +0100 (+01)
		// Remove the timezone abbreviation in parentheses, like (+01), (-03), (+UTC)
		$this->dateStr = preg_replace( '/\s\([+\-A-Za-z0-9]+\)$/', '', $this->dateStr );

		$methods = [
			'strtotime',
			// 'date_parse',
			'DateTimeImmutable',
			'IntlDateFormatter'
		];

		foreach ( $methods as $method ) {
			$timestamp = $this->$method();
			if ( $timestamp ) {
				return $timestamp;
			}
		}

		return false;
	}

	/**
	 * @return bool
	 */
	protected function isZeroDate() {
		return ( preg_match( '/^0{4}-0{2}-0{2}(?: 0{2}:0{2}:0{2})?$/', $this->dateStr ) === 1 );
	}

	/**
	 * @return false|int
	 */
	public function strtotime() {
		return strtotime( $this->dateStr );
	}

	/**
	 * @return false|int
	 */
	public function DateTimeImmutable() {
		// @see https://www.php.net/manual/en/datetimeimmutable.createfromformat.php
		$formats = [
			// Sat, 22 Feb 2025 19:07:38 +0100
			'D, d M Y H:i:s O',

			 // Sat, 22 Feb 2025 19:07:38 UTC
			'D, d M Y H:i:s T',

			 // Sat, 22 Feb 2025 19:07:38
			'D, d M Y H:i:s',

			 // 22 Feb 2025 19:07:38 +0100
			'd M Y H:i:s O',

			// 2025-02-22 19:07:38
			'Y-m-d H:i:s',

			// 2025/02/22 19:07:38
			'Y/m/d H:i:s',

			// 2025-02-22T19:07:38+01:00 (ISO 8601)
			'Y-m-d\TH:i:sP',
		];

		foreach ( $formats as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, $this->dateStr );

			if ( $date ) {
				return $date->getTimestamp();
			}
		}

		return false;
	}

	/**
	 * @return void|false|int
	 */
	public function date_parse() {
		// @see https://www.php.net/manual/en/function.date-parse.php
		$parsed = date_parse( $this->dateStr );

		if ( $parsed['error_count'] !== 0 || !$parsed['year'] || !$parsed['month'] || !$parsed['day'] ) {
			return;
		}

		$timezoneOffset = $parsed['zone'] ?? 0;

		// @see https://www.php.net/manual/en/function.timezone-name-from-abbr.php
		$timezoneName = timezone_name_from_abbr( '', $timezoneOffset * 60, false ) ?: 'UTC';
		$date = new \DateTime( 'now', new \DateTimeZone( $timezoneName ) );

		$date->setDate( $parsed['year'], $parsed['month'], $parsed['day'] );
		$date->setTime( $parsed['hour'] ?? 0, $parsed['minute'] ?? 0, $parsed['second'] ?? 0 );

		return $date->getTimestamp();
	}

	/**
	 * @return void|false|int
	 */
	public function IntlDateFormatter() {
		// @see: https://www.php.net/manual/en/intldateformatter.parse.php
		if ( !class_exists( 'IntlDateFormatter' ) ) {
			return;
		}
		$formatter = new \IntlDateFormatter(
			'en_US',
			\IntlDateFormatter::FULL,
			\IntlDateFormatter::FULL,
			'UTC',
			\IntlDateFormatter::GREGORIAN
		);

		return $formatter->parse( $this->dateStr );
	}

}

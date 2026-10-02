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

/**
 * A special page that lists protected pages
 *
 * @ingroup SpecialPage
 */

namespace MediaWiki\Extension\VisualData\Specials;

use SpecialPage;

class ManageQueries extends SpecialPage {

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		$listed = true;
		parent::__construct( 'VisualDataManageQueries', '', $listed );
	}

	/**
	 * @inheritDoc
	 */
	public function getPageTitle( $subpage = false ) {
		return SpecialPage::getTitleFor( 'VisualDataManage', 'Queries' );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'visualdata';
	}
}

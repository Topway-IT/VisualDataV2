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

use MediaWiki\Extension\VisualData\Aliases\Title as TitleClass;
use MediaWiki\MediaWikiServices;

class VisualDataHooks implements
	\MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook
{

	/**
	 * @param array $credits
	 * @return void
	 */
	public static function initExtension( $credits = [] ) {
	}

	/**
	 * @param MediaWiki\Installer\DatabaseUpdater|DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$base = __DIR__;
		$db = $updater->getDB();
		$dbType = $db->getType();
		$tables = DatabaseManager::$tables;

		foreach ( $tables as $tableName ) {
			$filename = "$base/../$dbType/$tableName.sql";

			// echo $filename;
			if ( file_exists( $filename ) && !$db->tableExists( $tableName ) ) {
				$updater->addExtensionUpdate(
					[
						'addTable',
						$tableName,
						$filename,
						true
					]
				);
			}
		}
	}

	/**
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'visualdataquery', [
			\VisualData::class,
			'parserFunctionQuery',
		] );
		$parser->setFunctionHook( 'visualdataprint', [
			\VisualData::class,
			'parserFunctionPrint',
		] );
	}
	
	/**
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 * @return void
	 */
	public static function onOutputPageParserOutput(
		OutputPage $out,
		ParserOutput $parserOutput,
	) {
		$title = $out->getTitle();
		$user = $out->getUser();

		if ( $parserOutput->getExtensionData( 'jsonforms' ) !== null ) {
			$out->addModules( 'ext.VisualData.AskQuery' );
		}
	}

	/**
	 * @param OutputPage $outputPage
	 * @param Skin $skin
	 * @return void
	 */
	public static function onBeforePageDisplay(
		OutputPage $outputPage,
		Skin $skin,
	) {
		$title = RequestContext::getMain()->getTitle();
		if ( !$title ) {
			return;
		}

		if ( $title && $title->isSpecial( 'JsonFormsManage' ) ) {
			$outputPage->addModules( 'ext.VisualData.SchemaBuilder' );
		}
	}

	/**
	 * @param Skin $skin
	 * @param array &$bar
	 * @return void
	 */
	public static function onSkinBuildSidebar( $skin, &$bar ) {
		if ( !empty( $GLOBALS['wgVisualDataDisableSidebarLink'] ) ) {
			return;
		}

		$user = $skin->getUser();
		$title = $skin->getTitle();

		if ( $user->isAllowed( 'visualdata-canmanagequeries' ) ) {
			$specialpage_title = SpecialPage::getTitleFor( 'VisualDataManage', 'Queries' );
			$bar[wfMessage( 'visualdata-sidepanel-section' )->text()][] = [
				'text' => wfMessage( 'visualdata-sidepanel-managequeries' )->text(),
				'href' => $specialpage_title->getLocalURL(),
			];
		}
	}

	/**
	 * @param User $user
	 * @param stdClass &$submittedData
	 * @param array &$errors
	 * @return void
	 */
	public static function onFormSubmitBeforeProcess(
		User $user,
		stdClass &$submittedData,
		&$errors = [],
	) {
	}

	/**
	 * @param User $user
	 * @param stdClass $submittedData
	 * @param array &$processedData
	 * @param array &$errors
	 * @return void
	 */
	public static function onFormSubmitBeforeSave(
		User $user,
		stdClass $submittedData,
		array &$processedData,
		&$errors = [],
	) {
	}

	/**
	 * @param User $user
	 * @param stdClass $submittedData
	 * @param array $processedData
	 * @param array &$errors
	 * @return void
	 */
	public static function onJsonFormsFormSubmitSuccess(
		User $user,
		stdClass $submittedData,
		array $processedData,
		array &$returnData,
		&$errors = [],
	) {
	return;
		trigger_error('^^submittedData ' . print_r($submittedData,1) );
		trigger_error('^^processedData ' . print_r($processedData,1) );
		exit;
	}

}

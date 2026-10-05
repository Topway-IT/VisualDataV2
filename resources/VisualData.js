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
 * along with VisualData. If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2026, https://wikisphere.org
 */

/* eslint-disable es-x/no-rest-spread-properties */
/* eslint-disable camelcase */
/* eslint-disable no-console */
/* eslint-disable no-unused-vars */

function VisualData() {
	this.initialize();
}

VisualData.prototype.onInitialized = async function (
	jsonFormsInstance,
	editor,
	eventData,
) {
	console.log('onInitialized jsonFormsInstance', jsonFormsInstance);
	console.log('onInitialized editor', editor);
	console.log('onInitialized eventData', eventData);

	//onInitialized.editor.schema.

	const schemaId = jsonFormsInstance.parseSchemaId(editor.schema.$id);

	if (schemaId === 'VDAskQuery') {
	}
};

VisualData.prototype.initialize = async function () {
	const enumProviders = new VisualData.EnumProviders();
	JsonForms.prototype.registerEnumProvider.call(
		this,
		'VDConditionsProps',
		enumProviders.VDConditionsProps,
	);
	JsonForms.prototype.registerEnumProvider.call(
		this,
		'VDConditionsComparator',
		enumProviders.VDConditionsComparator,
	);

	JsonForms.prototype.preprocessSchema = (editor, schema) => {
		console.log('editor', editor);
		console.log('schema', schema);

		const jsonFormsInstance = editor.jsonFormsInstance;

		if (jsonFormsInstance.parseSchemaId(schema.$id) === 'MetaSchema') {
			console.log('preprocessSchema', schema);

			let path = [
				'definitions',
				'string',
				'properties',
				'x-enum-provider',
				'enum',
			];
			let value = JsonForms.Utilities.getNestedProp(path, schema);
			value.push('VisualData');

			VisualData.Utilities.setNestedProp(path, schema, value);

			path = [
				'definitions',
				'stringOptions',
				'properties',
				'x-autocomplete-provider',
				'enum',
			];
			value = JsonForms.Utilities.getNestedProp(path, schema);
			value.push('VisualData');
			VisualData.Utilities.setNestedProp(path, schema, value);

			path = [
				'definitions',
				'stringOptionsMultiselect',
				'properties',
				'x-autocomplete-provider',
				'enum',
			];
			value = JsonForms.Utilities.getNestedProp(path, schema);
			value.push('VisualData');
			VisualData.Utilities.setNestedProp(path, schema, value);
		}

		return schema;
	};
};

(function ($) {
	const visualData = new VisualData();

	// eslint-disable-next-line no-undef
})(jQuery);



/* eslint-disable no-unused-vars */
/* eslint-disable no-case-declarations */

(function () {
	function EnumProviders() {}

	async function getVDSchemaPaths(jseditor, schemaName) {
		const jsonForm = jseditor.jsoneditor.jsonFormsInstance;
		const pageTitle = 'JsonSchema:' + schemaName;

		const contents = await jsonForm.fetchArticleContent(pageTitle);
		const schema = JSON.parse(contents);
		if (!schema) return [];

		options = { schema };

		const schemaLoader = await jseditor.jsoneditor.initializeLoader(options);

		const expandedSchema = schemaLoader.expandSchemaRecursive();

		return new VisualData.SchemaPaths(expandedSchema);
	}

	EnumProviders.prototype.VDConditionsProps = function () {
		const cache = {};
		return {
			source: async (jseditor, { item, watched }) => {
				const schemaName = watched['root.schema'] || watched['schema'];

				if (!schemaName) {
					return null;
				}

				jseditor.VDSchemaPaths = await getVDSchemaPaths(jseditor, schemaName);

				const values = [...jseditor.VDSchemaPaths.paths.values()];
				return values;
			},
		};
	};

	EnumProviders.prototype.VDConditionsComparator = function () {
		const getKeysByValue = function (map, value) {
			const keys = [];
			for (const [key, val] of map) {
				if (val === value) keys.push(key);
			}
			return keys;
		};

		const ALL = ['+', '=', '!', '~x', '~x~', 'x~', '>', '>=', '<', '<=', '-'];

		const COMPARATOR_MAP = {
			string: ['+', '=', '!', '~x', '~x~', 'x~', '-'],
			number: ['+', '=', '!', '>', '>=', '<', '<=', '-'],
			integer: ['+', '=', '!', '>', '>=', '<', '<=', '-'],
			boolean: ['+', '=', '!', '-'],
			null: ['+', '=', '!', '-'],
			date: ['+', '=', '!', '>', '>=', '<', '<=', '-'],
			datetime: ['+', '=', '!', '>', '>=', '<', '<=', '-'],
			time: ['+', '=', '!', '>', '>=', '<', '<=', '-'],
		};

		const getComparatorsForType = function (type) {
			const types = Array.isArray(type) ? type : [type];
			const set = new Set();
			for (const t of types) {
				(COMPARATOR_MAP[t] || ['=', '!', '+', '-']).forEach((op) =>
					set.add(op),
				);
			}
			return ALL.filter((op) => set.has(op));
		};

		const COMPARATOR_LABELS = {
			'+': 'any value',
			'=': 'equals',
			'!': 'not',
			'~x': 'starts with',
			'~x~': 'contains',
			'x~': 'ends with',
			'>': 'greater than',
			'>=': 'greater or equal',
			'<': 'less than',
			'<=': 'less or equal',
			'-': 'no value',
		};

		return {
			source: async (jseditor, { item, watched }) => {
				if (!watched['property']) {
					return null;
				}

				const propertyEditor = jseditor.getSiblingEditor('property');

				let VDSchemaPaths = propertyEditor.VDSchemaPaths;


				if (!VDSchemaPaths) {
					const schemaEditor = jseditor.jsoneditor.getEditor(
						propertyEditor.watched['root.schema'] ||
							propertyEditor.watched['schema'],
					);

					const schemaName = schemaEditor.getValue();
					if (!schemaName) {
						return null;
					}

					propertyEditor.VDSchemaPaths = await getVDSchemaPaths(
						jseditor,
						schemaName,
					);

					VDSchemaPaths = propertyEditor.VDSchemaPaths;

				}

				const jsonPaths = getKeysByValue(
					VDSchemaPaths.paths,
					watched['property'],
				);


				const types = new Set();

				for (const jsonPath of jsonPaths) {
					const subSchema = VDSchemaPaths.getSubschemaByJsonPath(jsonPath);

					if (!JsonForms.Utilities.isObject(subSchema)) continue;

					if (subSchema['format']) {
						switch (subSchema['format']) {
							case 'time':
								types.add('time');
								continue;
							case 'date':
								types.add('date');
								continue;
							case 'date-time':
							case 'datetime-local':
								types.add('datetime');
								continue;
						}
					}

					if (subSchema?.type) {
						[].concat(subSchema.type).forEach((t) => types.add(t));
					}
				}

				const typeList = [...types];

				if (!typeList.length) {
					return;
				}

				const typesEditor = jseditor.getSiblingEditor('types');

				if (typesEditor) {
					typesEditor.setValue(typeList);
				}

				const typesStringEditor = jseditor.getSiblingEditor('types_string');

				const typeListString = typeList.length === 1 ? typeList[0] : 'mixed';

				typesStringEditor.setValue(typeListString);

				return getComparatorsForType(typeList);
			},
			title: (jseditor, { item, watched }) => COMPARATOR_LABELS[item] || item,
		};
	};

	// eslint-disable-next-line no-undef
	VisualData.EnumProviders = EnumProviders;
})();


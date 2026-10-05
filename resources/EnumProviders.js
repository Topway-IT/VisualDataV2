// use IIFE, this ensure name is scoped

/* eslint-disable no-unused-vars */
/* eslint-disable no-case-declarations */

(function () {
	function EnumProviders() {}

	EnumProviders.prototype.VDConditionsProps = function () {
		const cache = {};
		return {
			source: async (jseditor, { item, watched }) => {
				const jsonForm = jseditor.jsoneditor.jsonFormsInstance;

				// console.log('VDConditionsProps item', item);
				// console.log('watched', watched);
				// console.log('jseditor', jseditor);

				const schemaValue = watched['root.schema'] || watched['schema'];

				console.log('VDConditionsProps property',watched );
				
				if (!schemaValue) {
					return null;
				}

				const pageTitle = 'JsonSchema:' + schemaValue;
				// if (cache[pageTitle]) {
				// 	return cache[pageTitle];
				// }

				const contents = await jsonForm.fetchArticleContent(pageTitle);
				const schema = JSON.parse(contents);
				if (!schema) return [];

				// console.log('schema', schema);
				options = { schema };

				const schemaLoader =
					await jseditor.jsoneditor.initializeLoader(options);
				// console.log('schemaLoader', schemaLoader);

				const expandedSchema = schemaLoader.expandSchemaRecursive();

				// console.log('expandedSchema', expandedSchema);

				const schemaPaths = new VisualData.SchemaPaths(expandedSchema);

				jseditor.jsoneditor.VDSchemaPaths = schemaPaths;

				// console.log('schemaPaths', schemaPaths);

				const values = [...schemaPaths.paths.values()];
				// console.log('schemaPaths values', values);
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
			source: (jseditor, { item, watched }) => {
			
				console.log('VDConditionsComparator comparator', watched);
				// console.log('watched comparator', watched);
				if (!watched['property']) {
					return null;
				}
				// console.log(
				// 	'jseditor.VDSchemaPaths',
				// 	jseditor.jsoneditor.VDSchemaPaths,
				// );

				if (!jseditor.jsoneditor.VDSchemaPaths) {
					return null;
				}

				const jsonPaths = getKeysByValue(
					jseditor.jsoneditor.VDSchemaPaths.paths,
					watched['property'],
				);

				// console.log('comparator jsonPaths', jsonPaths);

				const types = new Set();

				for (const jsonPath of jsonPaths) {
					const subSchema =
						jseditor.jsoneditor.VDSchemaPaths.getSubschemaByJsonPath(jsonPath);

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

				const typeList = [...types]; // e.g. ['string', 'null']
				// console.log('comparator typeList', typeList);

				const typesEditor = jseditor.jsoneditor.getEditor([
					...jseditor.path.slice(0, -1),
					'types',
				]);
				typesEditor.setValue(typeList);


				const typesStringEditor = jseditor.jsoneditor.getEditor([
					...jseditor.path.slice(0, -1),
					'types_string',
				]);

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


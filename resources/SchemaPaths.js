(function () {
    'use strict';

    // ---------------------------------------------------------------------
    // Shared keyword sets (declared once, used by walker + resolvers)
    // ---------------------------------------------------------------------

    /** Keywords whose value IS a single schema. */
    var SCHEMA_KEYWORDS = [
        'additionalProperties', 'additionalItems', 'contains',
        'propertyNames', 'if', 'then', 'else', 'not',
        'unevaluatedProperties', 'unevaluatedItems',
    ];

    /** Keywords whose value is a MAP of schemas. */
    var SCHEMA_MAP_KEYWORDS = [
        'properties', 'patternProperties', 'definitions',
        '$defs', 'dependentSchemas',
    ];

    /** Keywords whose value is an ARRAY of schemas (non-positional). */
    var SCHEMA_ARRAY_KEYWORDS = ['allOf', 'anyOf', 'oneOf'];

    /** Keywords whose value is an ARRAY of schemas (positional / tuple). */
    var SCHEMA_TUPLE_KEYWORDS = ['items', 'prefixItems'];

    /**
     * Keywords a path resolver should consume as "next segment is an
     * entry name" (subset of SCHEMA_MAP_KEYWORDS that appears literally
     * in paths emitted by the walker).
     */
    var RESOLVER_MAP_KEYWORDS = SCHEMA_MAP_KEYWORDS;

    /**
     * Keywords a path resolver should consume as "next segment is an
     * index" (array-of-schemas forms).
     */
    var RESOLVER_ARRAY_KEYWORDS = SCHEMA_ARRAY_KEYWORDS.concat(['prefixItems']);

    // ---------------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------------

    /**
     * @constructor
     * @param {object} schema
     * @param {object} [opts]
     * @param {string} [opts.sep='.']  path separator
     */
    function SchemaPaths(schema, opts) {
        opts = opts || {};

        this.schema = schema;
        this.opts = opts;
        this.sep = opts.sep != null ? opts.sep : '.';

        /** @type {Map<string,string>} structural path -> clean path */
        this.paths = schemaToPaths(schema, opts);

        /** @type {Map<string,string>} clean path -> structural path */
        this._cleanToStructural = new Map();
        for (const [structural, clean] of this.paths) {
            if (!this._cleanToStructural.has(clean)) {
                this._cleanToStructural.set(clean, structural);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Walker (private)
    // ---------------------------------------------------------------------

    /**
     * Walk a JSON Schema and return a Map of structural path -> clean path.
     *
     * Only LEAF schemas are emitted. A leaf is a schema node with no
     * schema-bearing children (`properties`, `items`, `allOf`, `$defs`,
     * `additionalProperties`, ...).
     *
     * @param {object} schema
     * @param {object} [opts]
     * @param {string} [opts.sep='.']  path separator
     * @returns {Map<string,string>}
     */
    function schemaToPaths(schema, opts) {
        opts = opts || {};
        var sep = opts.sep != null ? opts.sep : '.';

        var out = new Map();
        var seen = new WeakSet();

        function join(base, key) {
            return base ? base + sep + key : String(key);
        }

        function hasSchemaChildren(node) {
            if (!node || typeof node !== 'object' || Array.isArray(node)) return false;
            var keys = Object.keys(node);
            for (var i = 0; i < keys.length; i++) {
                var key = keys[i];
                var value = node[key];
                if (!value || typeof value !== 'object') continue;

                if (SCHEMA_KEYWORDS.indexOf(key) !== -1) return true;

                if (SCHEMA_MAP_KEYWORDS.indexOf(key) !== -1) {
                    if (!Array.isArray(value) && Object.keys(value).length > 0) return true;
                    continue;
                }
                if (SCHEMA_ARRAY_KEYWORDS.indexOf(key) !== -1) {
                    if (Array.isArray(value) && value.length > 0) return true;
                    if (!Array.isArray(value)) return true;
                    continue;
                }
                if (SCHEMA_TUPLE_KEYWORDS.indexOf(key) !== -1) {
                    if (Array.isArray(value) && value.length > 0) return true;
                    if (!Array.isArray(value)) return true;
                    continue;
                }
            }
            return false;
        }

        function visit(node, structural, clean) {
            if (node && typeof node === 'object') {
                if (seen.has(node)) return;
                seen.add(node);
            }

            var isObj = node && typeof node === 'object' && !Array.isArray(node);
            var isArr = Array.isArray(node);
            if (!isObj && !isArr) return;

            if (isArr) {
                for (var i = 0; i < node.length; i++) {
                    visit(node[i], join(structural, i), clean);
                }
                return;
            }

            if (!hasSchemaChildren(node)) {
                out.set(structural, clean);
                return;
            }

            var keys = Object.keys(node);
            for (var k = 0; k < keys.length; k++) {
                var key = keys[k];
                var value = node[key];

                if (SCHEMA_KEYWORDS.indexOf(key) !== -1) {
                    if (value && typeof value === 'object') {
                        visit(value, join(structural, key), clean);
                    }
                    continue;
                }
                if (SCHEMA_MAP_KEYWORDS.indexOf(key) !== -1) {
                    if (value && typeof value === 'object' && !Array.isArray(value)) {
                        var names = Object.keys(value);
                        for (var n = 0; n < names.length; n++) {
                            visit(
                                value[names[n]],
                                join(join(structural, key), names[n]),
                                join(clean, names[n])
                            );
                        }
                    }
                    continue;
                }
                if (SCHEMA_ARRAY_KEYWORDS.indexOf(key) !== -1) {
                    if (Array.isArray(value)) {
                        for (var a = 0; a < value.length; a++) {
                            visit(value[a], join(join(structural, key), a), clean);
                        }
                    } else if (value && typeof value === 'object') {
                        visit(value, join(structural, key), clean);
                    }
                    continue;
                }
                if (SCHEMA_TUPLE_KEYWORDS.indexOf(key) !== -1) {
                    if (Array.isArray(value)) {
                        for (var t = 0; t < value.length; t++) {
                            visit(
                                value[t],
                                join(join(structural, key), t),
                                join(clean, t)
                            );
                        }
                    } else if (value && typeof value === 'object') {
                        visit(value, join(structural, key), clean);
                    }
                    continue;
                }
                // Non-schema keyword value — skip.
            }
        }

        visit(schema, '', '');
        return out;
    }

    // ---------------------------------------------------------------------
    // Path resolvers (private)
    // ---------------------------------------------------------------------

    /**
     * Resolve a leaf schema object from a structural path.
     * Only handles the schema-bearing keywords the walker knows about.
     *
     * @param {object} schema
     * @param {string} structural
     * @param {string} sep
     * @returns {*}
     */
    function getByStructuralPath(schema, structural, sep) {
        if (structural === '') return schema;

        var parts = structural.split(sep);
        var node = schema;

        for (var i = 0; i < parts.length; i++) {
            var key = parts[i];
            if (node == null) return undefined;

            // Map-of-schemas: key is the map name, next part is the entry name.
            if (RESOLVER_MAP_KEYWORDS.indexOf(key) !== -1) {
                var name = parts[++i];
                node = node[key] != null ? node[key][name] : undefined;
                continue;
            }

            // Array-of-schemas: key is the array name, next part is the index.
            if (RESOLVER_ARRAY_KEYWORDS.indexOf(key) !== -1) {
                var idx = Number(parts[++i]);
                node = node[key] != null ? node[key][idx] : undefined;
                continue;
            }

            // `items` — single schema or tuple.
            if (key === 'items') {
                if (Array.isArray(node.items)) {
                    var tupleIdx = Number(parts[++i]);
                    node = node.items[tupleIdx];
                } else {
                    node = node.items;
                }
                continue;
            }

            // Single-schema keyword.
            node = node[key];
        }

        return node;
    }

    /**
     * Resolve a subschema by JSON path. Accepts both structural paths
     * (with keyword names, e.g. "properties.a.oneOf.1.x") and clean
     * paths (keywords omitted, e.g. "a.oneOf.1.x").
     *
     * Resolution rules, per segment:
     *  1. Map-of-schemas keyword → next segment is an entry name.
     *  2. Array-of-schemas keyword → next segment is an index.
     *  3. `items` as tuple array → next segment is an index.
     *  4. `items` as a single schema → descend, no index consumed.
     *  5. Fallback: `node.properties[seg]` (tolerates clean paths).
     *  6. Fallback: numeric segment → tuple index into `prefixItems`/`items`.
     *  7. Fallback: direct key access (`node[seg]`).
     *
     * @param {object} schema
     * @param {string} jsonPath
     * @param {string} sep
     * @returns {*}
     */
    function getSubschemaByJsonPath(schema, jsonPath, sep) {
        if (!schema || typeof schema !== 'object') return undefined;
        if (jsonPath === '' || jsonPath == null) return schema;

        var parts = String(jsonPath).split(sep);
        var node = schema;

        for (var i = 0; i < parts.length; i++) {
            if (node == null || typeof node !== 'object') return undefined;

            var seg = parts[i];

            // Rule 1: map-of-schemas keyword.
            if (RESOLVER_MAP_KEYWORDS.indexOf(seg) !== -1) {
                var map = node[seg];
                if (!map || typeof map !== 'object') return undefined;
                var name = parts[++i];
                if (name === undefined) return undefined;
                node = map[name];
                continue;
            }

            // Rule 2: array-of-schemas keyword.
            if (RESOLVER_ARRAY_KEYWORDS.indexOf(seg) !== -1) {
                var arr = node[seg];
                if (!Array.isArray(arr)) return undefined;
                var idx = Number(parts[++i]);
                if (!Number.isInteger(idx) || idx < 0 || idx >= arr.length) {
                    return undefined;
                }
                node = arr[idx];
                continue;
            }

            // Rule 3: `items` as tuple array.
            if (seg === 'items' && Array.isArray(node.items)) {
                var tidx = Number(parts[++i]);
                if (
                    !Number.isInteger(tidx) ||
                    tidx < 0 ||
                    tidx >= node.items.length
                ) {
                    return undefined;
                }
                node = node.items[tidx];
                continue;
            }

            // Rule 4: `items` as a single schema.
            if (
                seg === 'items' &&
                node.items &&
                typeof node.items === 'object' &&
                !Array.isArray(node.items)
            ) {
                node = node.items;
                continue;
            }

            // Rule 5: property-name fallback (tolerates clean paths).
            if (
                node.properties &&
                Object.prototype.hasOwnProperty.call(node.properties, seg)
            ) {
                node = node.properties[seg];
                continue;
            }

            // Rule 6: numeric segment → tuple index fallback.
            var n = Number(seg);
            if (Number.isInteger(n) && String(n) === seg) {
                if (
                    Array.isArray(node.prefixItems) &&
                    n < node.prefixItems.length
                ) {
                    node = node.prefixItems[n];
                    continue;
                }
                if (Array.isArray(node.items) && n < node.items.length) {
                    node = node.items[n];
                    continue;
                }
            }

            // Rule 7: direct key access.
            if (node[seg] !== undefined) {
                node = node[seg];
                continue;
            }

            return undefined;
        }

        return node;
    }

    // ---------------------------------------------------------------------
    // Prototype
    // ---------------------------------------------------------------------

    Object.defineProperty(SchemaPaths.prototype, 'size', {
        get: function () {
            return this.paths.size;
        },
        enumerable: false,
        configurable: true,
    });

    SchemaPaths.prototype.structuralPaths = function () {
        return Array.from(this.paths.keys());
    };

    SchemaPaths.prototype.cleanPaths = function () {
        return Array.from(this.paths.values());
    };

    SchemaPaths.prototype.entries = function () {
        return Array.from(this.paths.entries());
    };

    SchemaPaths.prototype.list = function () {
        return this.entries().map(function (pair) {
            return { structural: pair[0], clean: pair[1] };
        });
    };

    SchemaPaths.prototype.clean = function (structural) {
        return this.paths.get(structural);
    };

    SchemaPaths.prototype.structural = function (clean) {
        return this._cleanToStructural.get(clean);
    };

    SchemaPaths.prototype.has = function (structural) {
        return this.paths.has(structural);
    };

    SchemaPaths.prototype.hasClean = function (clean) {
        return this._cleanToStructural.has(clean);
    };

    SchemaPaths.prototype.getSchema = function (structural) {
        if (!this.paths.has(structural)) return undefined;
        return getByStructuralPath(this.schema, structural, this.sep);
    };

    SchemaPaths.prototype.getSchemaByClean = function (clean) {
        var structural = this._cleanToStructural.get(clean);
        if (structural === undefined) return undefined;
        return getByStructuralPath(this.schema, structural, this.sep);
    };

    /**
     * Resolve a subschema by JSON path. Accepts both structural paths
     * (with keyword names, e.g. "properties.a.oneOf.1.x") and clean
     * paths (keywords omitted, e.g. "a.oneOf.1.x").
     *
     * Unlike `getSchema`, this does NOT require the path to have been
     * emitted by the walker.
     *
     * @param {string} jsonPath
     * @returns {*}
     */
    SchemaPaths.prototype.getSubschemaByJsonPath = function (jsonPath) {
        return getSubschemaByJsonPath(this.schema, jsonPath, this.sep);
    };

    SchemaPaths.prototype.toObject = function () {
        return Object.fromEntries(this.paths);
    };

    SchemaPaths.prototype.toJSON = function () {
        return this.toObject();
    };

    SchemaPaths.prototype[Symbol.iterator] = function () {
        return this.paths.entries();
    };

    // eslint-disable-next-line no-undef
    VisualData.SchemaPaths = SchemaPaths;
})();

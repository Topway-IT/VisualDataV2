/* global JsonForms */

// use IIFE, this ensure name is scoped
( function () {
	function Utilities() {}

	Utilities.prototype.setNestedProp = function ( path, obj, value ) {
		if ( !Array.isArray( path ) || path.length === 0 ) {
			return value;
		}

		let target = obj;
		const last = path.length - 1;

		for ( let i = 0; i < last; i++ ) {
			const key = path[ i ];
			if ( target[ key ] === null || typeof target[ key ] !== 'object' ) {
				target[ key ] = typeof path[ i + 1 ] === 'number' ? [] : {};
			}
			target = target[ key ];
		}

		target[ path[ last ] ] = value;

		return target;
	}

	// attach instance
	VisualData.Utilities = new Utilities();
}() );

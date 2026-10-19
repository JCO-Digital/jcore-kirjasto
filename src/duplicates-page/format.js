// Format in the admin language rather than the browser's.
const LOCALE = document.documentElement.lang || undefined;

export const formatDate = ( dateString, withTime = false ) =>
	new Intl.DateTimeFormat( LOCALE, {
		dateStyle: 'medium',
		...( withTime ? { timeStyle: 'short' } : {} ),
	} ).format( new Date( dateString ) );

export const formatNumber = ( value ) =>
	new Intl.NumberFormat( LOCALE ).format( value );

export function formatBytes( bytes ) {
	const units = [ 'byte', 'kilobyte', 'megabyte', 'gigabyte' ];
	let value = bytes;
	let unit = 0;

	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit++;
	}

	return new Intl.NumberFormat( LOCALE, {
		style: 'unit',
		unit: units[ unit ],
		unitDisplay: 'short',
		maximumFractionDigits: unit > 1 ? 1 : 0,
	} ).format( value );
}

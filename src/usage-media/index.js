import { __ } from '@wordpress/i18n';
import './style.scss';

/**
 * Adds a used/unused filter to the media library grid toolbar.
 *
 * The filter sets a `jcore_kirjasto_usage` prop on the attachments
 * collection, which the query-attachments request passes on to PHP.
 */
const media = window.wp?.media;

if ( media?.view?.AttachmentsBrowser && media.view.AttachmentFilters ) {
	const UsageFilter = media.view.AttachmentFilters.extend( {
		id: 'jcore-kirjasto-usage-filter',

		createFilters() {
			// `null` leaves the prop out of the request entirely.
			this.filters = {
				all: {
					text: __( 'All usage', 'jcore-kirjasto' ),
					props: { jcore_kirjasto_usage: null },
					priority: 10,
				},
				used: {
					text: __( 'Used', 'jcore-kirjasto' ),
					props: { jcore_kirjasto_usage: 'used' },
					priority: 20,
				},
				unused: {
					text: __( 'Not used', 'jcore-kirjasto' ),
					props: { jcore_kirjasto_usage: 'unused' },
					priority: 30,
				},
			};
		},
	} );

	const AttachmentsBrowser = media.view.AttachmentsBrowser;

	media.view.AttachmentsBrowser = AttachmentsBrowser.extend( {
		createToolbar() {
			AttachmentsBrowser.prototype.createToolbar.apply( this, arguments );

			this.toolbar.set(
				'jcoreKirjastoUsageLabel',
				new media.view.Label( {
					value: __( 'Filter by usage', 'jcore-kirjasto' ),
					attributes: { for: 'jcore-kirjasto-usage-filter' },
					priority: -70,
				} ).render()
			);

			this.toolbar.set(
				'jcoreKirjastoUsage',
				new UsageFilter( {
					controller: this.controller,
					model: this.collection.props,
					priority: -70,
				} ).render()
			);
		},
	} );
}

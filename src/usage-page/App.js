import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	PanelRow,
	ProgressBar,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const PATH = '/jcore-kirjasto/v1/index';

// Format in the admin language rather than the browser's.
const LOCALE = document.documentElement.lang || undefined;

const formatDate = ( dateString ) =>
	new Intl.DateTimeFormat( LOCALE, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} ).format( new Date( dateString ) );

const formatNumber = ( value ) =>
	new Intl.NumberFormat( LOCALE ).format( value );

function Stat( { label, value, href } ) {
	return (
		<div className="jcore-kirjasto__stat">
			<span className="jcore-kirjasto__stat-value">
				{ href ? (
					<a href={ href }>{ formatNumber( value ) }</a>
				) : (
					formatNumber( value )
				) }
			</span>
			<span className="jcore-kirjasto__stat-label">{ label }</span>
		</div>
	);
}

export default function App() {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isStarting, setIsStarting ] = useState( false );
	const isStepping = useRef( false );
	const isMounted = useRef( true );

	// Steps the running rebuild while this screen is open. WP-Cron advances
	// the same rebuild in the background, so leaving the screen is safe.
	const runSteps = useCallback( async () => {
		if ( isStepping.current ) {
			return;
		}
		isStepping.current = true;
		try {
			let next;
			do {
				next = await apiFetch( {
					path: `${ PATH }/step`,
					method: 'POST',
				} );
				if ( isMounted.current ) {
					setStatus( next );
				}
			} while ( next.running && isMounted.current );
		} catch {
			setError( __( 'Indexing failed.', 'jcore-kirjasto' ) );
		} finally {
			isStepping.current = false;
		}
	}, [] );

	useEffect( () => {
		apiFetch( { path: PATH } )
			.then( ( response ) => {
				setStatus( response );
				if ( response.running ) {
					runSteps();
				}
			} )
			.catch( () =>
				setError(
					__( 'Failed to load the index status.', 'jcore-kirjasto' )
				)
			);

		return () => {
			isMounted.current = false;
		};
	}, [ runSteps ] );

	const rebuild = async () => {
		setIsStarting( true );
		setError( null );
		try {
			setStatus(
				await apiFetch( { path: `${ PATH }/rebuild`, method: 'POST' } )
			);
			runSteps();
		} catch {
			setError( __( 'Failed to start the rebuild.', 'jcore-kirjasto' ) );
		} finally {
			setIsStarting( false );
		}
	};

	if ( ! status ) {
		return (
			<div className="wrap jcore-kirjasto">
				<h1>{ __( 'Media Usage', 'jcore-kirjasto' ) }</h1>
				{ error ? (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) : (
					<Spinner />
				) }
			</div>
		);
	}

	const { progress, stats, links } = status;
	const percent = progress?.total
		? Math.min( 100, ( progress.processed / progress.total ) * 100 )
		: 0;

	return (
		<div className="wrap jcore-kirjasto">
			<h1>{ __( 'Media Usage', 'jcore-kirjasto' ) }</h1>
			<hr className="wp-header-end" />
			<p className="description">
				{ __(
					'Every post, page, pattern, template, term and ACF options page is scanned for the attachments it uses. The media library shows the result in the "Used in" column and in the attachment details.',
					'jcore-kirjasto'
				) }
			</p>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<Panel>
				<PanelBody
					title={ __( 'Usage index', 'jcore-kirjasto' ) }
					initialOpen={ true }
				>
					<PanelRow>
						<p className="jcore-kirjasto__built">
							{ status.built_at
								? sprintf(
										/* translators: %s: date and time */
										__(
											'Last built %s. Changes to content are indexed as they are saved.',
											'jcore-kirjasto'
										),
										formatDate( status.built_at )
								  )
								: __(
										'The index has not been built yet. Until it is, attachments may show as not used even though they are.',
										'jcore-kirjasto'
								  ) }
						</p>
					</PanelRow>
					{ status.running && (
						<PanelRow>
							<div className="jcore-kirjasto__progress">
								<ProgressBar value={ percent } />
								<span>
									{ sprintf(
										/* translators: 1: items scanned so far, 2: items in total */
										__(
											'%1$s of %2$s items scanned',
											'jcore-kirjasto'
										),
										formatNumber(
											Math.min(
												progress.processed,
												progress.total
											)
										),
										formatNumber( progress.total )
									) }
								</span>
							</div>
						</PanelRow>
					) }
					<PanelRow>
						<div className="jcore-kirjasto__actions">
							<Button
								variant="secondary"
								onClick={ rebuild }
								isBusy={ isStarting || status.running }
								disabled={ isStarting || status.running }
							>
								{ status.running
									? __( 'Rebuilding…', 'jcore-kirjasto' )
									: __( 'Rebuild index', 'jcore-kirjasto' ) }
							</Button>
							<p className="description">
								{ __(
									'Only needed after content was changed directly in the database, e.g. by an import or a search-replace.',
									'jcore-kirjasto'
								) }
							</p>
						</div>
					</PanelRow>
				</PanelBody>
				<PanelBody
					title={ __( 'Summary', 'jcore-kirjasto' ) }
					initialOpen={ true }
				>
					<div className="jcore-kirjasto__stats">
						<Stat
							label={ __( 'Attachments', 'jcore-kirjasto' ) }
							value={ stats.attachments }
						/>
						<Stat
							label={ __( 'Used', 'jcore-kirjasto' ) }
							value={ stats.used }
							href={ links.used }
						/>
						<Stat
							label={ __( 'Not used', 'jcore-kirjasto' ) }
							value={ stats.unused }
							href={ links.unused }
						/>
						<Stat
							label={ __( 'References', 'jcore-kirjasto' ) }
							value={ stats.references }
						/>
					</div>
				</PanelBody>
			</Panel>
		</div>
	);
}

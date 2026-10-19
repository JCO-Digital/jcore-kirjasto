import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	PanelRow,
	ProgressBar,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import Group from './Group';
import { formatDate, formatNumber } from './format';

const PATH = '/jcore-kirjasto/v1/duplicates';
const PER_PAGE = 20;

function Stat( { label, value } ) {
	return (
		<div className="jcore-kirjasto__stat">
			<span className="jcore-kirjasto__stat-value">
				{ formatNumber( value ) }
			</span>
			<span className="jcore-kirjasto__stat-label">{ label }</span>
		</div>
	);
}

/**
 * What a merge did, in words.
 *
 * @param {Object[]} results Per merged attachment: `deleted` and `used`.
 * @return {Object} `status` and `message` of a notice.
 */
function describe( results ) {
	const deleted = results.filter( ( result ) => result.deleted ).length;
	const kept = results.length - deleted;

	const parts = [
		sprintf(
			/* translators: %d: number of files. */
			_n(
				'%d duplicate removed.',
				'%d duplicates removed.',
				deleted,
				'jcore-kirjasto'
			),
			deleted
		),
	];

	if ( kept ) {
		parts.push(
			sprintf(
				/* translators: %d: number of files. */
				_n(
					'%d file is still used where it could not be changed, so it was kept.',
					'%d files are still used where they could not be changed, so they were kept.',
					kept,
					'jcore-kirjasto'
				),
				kept
			)
		);
	}

	return { status: kept ? 'warning' : 'success', message: parts.join( ' ' ) };
}

export default function App() {
	const [ data, setData ] = useState( null );
	const [ kind, setKind ] = useState( 'all' );
	const [ page, setPage ] = useState( 1 );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ isStarting, setIsStarting ] = useState( false );
	const isStepping = useRef( false );
	const isMounted = useRef( true );

	const load = useCallback(
		async () =>
			apiFetch( {
				path: addQueryArgs( PATH, { kind, page, per_page: PER_PAGE } ),
			} ).then( ( response ) => {
				if ( isMounted.current ) {
					setData( response );
				}
				return response;
			} ),
		[ kind, page ]
	);

	// Steps the running scan while this screen is open. WP-Cron advances the
	// same scan in the background, so leaving the screen is safe.
	const runSteps = useCallback( async () => {
		if ( isStepping.current ) {
			return;
		}
		isStepping.current = true;
		try {
			let status;
			do {
				status = await apiFetch( {
					path: `${ PATH }/step`,
					method: 'POST',
				} );
				if ( isMounted.current ) {
					setData( ( current ) => current && { ...current, status } );
				}
			} while ( status.running && isMounted.current );

			if ( isMounted.current ) {
				await load();
			}
		} catch {
			setError( __( 'Scanning failed.', 'jcore-kirjasto' ) );
		} finally {
			isStepping.current = false;
		}
	}, [ load ] );

	useEffect( () => {
		isMounted.current = true;
		load()
			.then( ( response ) => {
				if ( response.status.running ) {
					runSteps();
				}
			} )
			.catch( () =>
				setError(
					__( 'Failed to load the duplicates.', 'jcore-kirjasto' )
				)
			);

		return () => {
			isMounted.current = false;
		};
	}, [ load, runSteps ] );

	const scan = async () => {
		setIsStarting( true );
		setError( null );
		try {
			const status = await apiFetch( {
				path: `${ PATH }/scan`,
				method: 'POST',
			} );
			setData( ( current ) => ( { ...current, status } ) );
			runSteps();
		} catch {
			setError( __( 'Failed to start the scan.', 'jcore-kirjasto' ) );
		} finally {
			setIsStarting( false );
		}
	};

	const merge = async ( keep, remove ) => {
		const response = await apiFetch( {
			path: `${ PATH }/merge`,
			method: 'POST',
			data: { keep, remove },
		} );
		setNotice( describe( response.results ) );
		await load();
	};

	const dismiss = async ( ids ) => {
		await apiFetch( {
			path: `${ PATH }/dismiss`,
			method: 'POST',
			data: { ids },
		} );
		setNotice( {
			status: 'success',
			message: __(
				'The files will no longer be listed as duplicates.',
				'jcore-kirjasto'
			),
		} );
		await load();
	};

	if ( ! data ) {
		return (
			<div className="wrap jcore-kirjasto">
				<h1>{ __( 'Duplicate Media', 'jcore-kirjasto' ) }</h1>
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

	const { status, groups, total } = data;
	const { progress, counts } = status;
	const percent = progress?.total
		? Math.min( 100, ( progress.processed / progress.total ) * 100 )
		: 0;
	const pages = Math.max( 1, Math.ceil( total / PER_PAGE ) );

	return (
		<div className="wrap jcore-kirjasto jcore-kirjasto-duplicates">
			<h1>{ __( 'Duplicate Media', 'jcore-kirjasto' ) }</h1>
			<hr className="wp-header-end" />
			<p className="description">
				{ __(
					'Files uploaded more than once, and images that look the same though they were resized, recompressed or saved in another format. Keep one, and everything that used the others is changed to use it.',
					'jcore-kirjasto'
				) }
			</p>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<Panel>
				<PanelBody
					title={ __( 'Scan', 'jcore-kirjasto' ) }
					initialOpen={ true }
				>
					<PanelRow>
						<p className="jcore-kirjasto__built">
							{ status.scanned_at
								? sprintf(
										/* translators: %s: date and time */
										__(
											'Last scanned %s. New uploads are checked as they are added.',
											'jcore-kirjasto'
										),
										formatDate( status.scanned_at, true )
								  )
								: __(
										'The media library has not been scanned yet.',
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
										/* translators: 1: files scanned so far, 2: files in total */
										__(
											'%1$s of %2$s files scanned',
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
								onClick={ scan }
								isBusy={ isStarting || status.running }
								disabled={ isStarting || status.running }
							>
								{ status.running
									? __( 'Scanning…', 'jcore-kirjasto' )
									: __( 'Scan again', 'jcore-kirjasto' ) }
							</Button>
							<p className="description">
								{ __(
									'Only files that changed are read again.',
									'jcore-kirjasto'
								) }
							</p>
						</div>
					</PanelRow>
					<div className="jcore-kirjasto__stats">
						<Stat
							label={ __( 'Files scanned', 'jcore-kirjasto' ) }
							value={ counts.fingerprinted }
						/>
						<Stat
							label={ __(
								'Groups of identical files',
								'jcore-kirjasto'
							) }
							value={ status.groups.identical }
						/>
						<Stat
							label={ __(
								'Groups of images that look the same',
								'jcore-kirjasto'
							) }
							value={ status.groups.similar }
						/>
						{ counts.missing > 0 && (
							<Stat
								label={ __(
									'Files missing on disk',
									'jcore-kirjasto'
								) }
								value={ counts.missing }
							/>
						) }
					</div>
					{ counts.missing > 0 && (
						<p className="description">
							{ __(
								'Attachments whose file is not on this server cannot be compared. On a development copy that loads its media from the live site, most files may be missing.',
								'jcore-kirjasto'
							) }
						</p>
					) }
				</PanelBody>
			</Panel>

			<div className="jcore-kirjasto-duplicates__toolbar">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Show', 'jcore-kirjasto' ) }
					value={ kind }
					options={ [
						{
							label: __( 'All duplicates', 'jcore-kirjasto' ),
							value: 'all',
						},
						{
							label: __( 'Identical files', 'jcore-kirjasto' ),
							value: 'identical',
						},
						{
							label: __(
								'Images that look the same',
								'jcore-kirjasto'
							),
							value: 'similar',
						},
					] }
					onChange={ ( value ) => {
						setKind( value );
						setPage( 1 );
					} }
				/>
				<span>
					{ sprintf(
						/* translators: %s: number of groups. */
						_n( '%s group', '%s groups', total, 'jcore-kirjasto' ),
						formatNumber( total )
					) }
				</span>
			</div>

			{ ! groups.length && ! status.running && (
				<p>{ __( 'No duplicates found.', 'jcore-kirjasto' ) }</p>
			) }

			{ groups.map( ( group ) => (
				<Group
					key={ group.entries
						.map( ( entry ) => entry.id )
						.join( '-' ) }
					group={ group }
					canMerge={ status.can_merge }
					mergeNote={ status.merge_note }
					onMerge={ merge }
					onDismiss={ dismiss }
				/>
			) ) }

			{ pages > 1 && (
				<div className="jcore-kirjasto-duplicates__pages">
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Previous', 'jcore-kirjasto' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: number of pages. */
							__( 'Page %1$d of %2$d', 'jcore-kirjasto' ),
							page,
							pages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Next', 'jcore-kirjasto' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

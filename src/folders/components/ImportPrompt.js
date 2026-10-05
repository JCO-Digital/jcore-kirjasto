import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice, ProgressBar } from '@wordpress/components';
import { importStep } from '../store';
import { settings, formatNumber } from '../settings';
import { read, write } from '../storage';

const DISMISSED = 'imports-dismissed';

/**
 * Offers to import the folders of another media folder plugin, and runs the
 * import a batch at a time.
 */
export default function ImportPrompt() {
	const [ sources, setSources ] = useState( () => {
		const dismissed = read( DISMISSED, [] );

		return settings.imports.filter(
			( source ) => ! dismissed.includes( source.source )
		);
	} );
	const [ running, setRunning ] = useState( null );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );

	if ( ! sources.length && ! message && ! error ) {
		return null;
	}

	const remove = ( source ) =>
		setSources( ( list ) =>
			list.filter( ( item ) => item.source !== source.source )
		);

	const start = async ( source ) => {
		setError( '' );
		setRunning( { source: source.source, offset: 0, total: source.files } );

		try {
			let step;
			let offset = 0;
			do {
				step = await importStep( source.source, offset );
				offset = step.offset;
				setRunning( {
					source: source.source,
					offset,
					total: step.total,
				} );
			} while ( ! step.done );

			remove( source );
			setMessage(
				sprintf(
					/* translators: 1: number of folders, 2: plugin name. */
					_n(
						'Imported %1$s folder from %2$s.',
						'Imported %1$s folders from %2$s.',
						step.imported,
						'jcore-kirjasto'
					),
					formatNumber( step.imported ),
					source.label
				)
			);
		} catch ( caught ) {
			setError(
				caught?.message ||
					__( 'The folders could not be imported.', 'jcore-kirjasto' )
			);
		}

		setRunning( null );
	};

	const dismiss = ( source ) => {
		write( DISMISSED, [ ...read( DISMISSED, [] ), source.source ] );
		remove( source );
	};

	return (
		<div className="jcore-kirjasto-folders__import">
			{ message && (
				<Notice status="success" onRemove={ () => setMessage( '' ) }>
					{ message }
				</Notice>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ sources.map( ( source ) => (
				<div
					key={ source.source }
					className="jcore-kirjasto-folders__import-source"
				>
					<p>
						{ sprintf(
							/* translators: 1: plugin name, 2: "25 folders", 3: "279 files". */
							__( '%1$s has %2$s with %3$s.', 'jcore-kirjasto' ),
							source.label,
							sprintf(
								/* translators: %s: number of folders. */
								_n(
									'%s folder',
									'%s folders',
									source.folders,
									'jcore-kirjasto'
								),
								formatNumber( source.folders )
							),
							sprintf(
								/* translators: %s: number of files. */
								_n(
									'%s file',
									'%s files',
									source.files,
									'jcore-kirjasto'
								),
								formatNumber( source.files )
							)
						) }
					</p>

					{ running?.source === source.source ? (
						<>
							<ProgressBar
								value={
									running.total
										? ( running.offset / running.total ) *
										  100
										: undefined
								}
							/>
							<p className="jcore-kirjasto-folders__progress">
								{ sprintf(
									/* translators: 1: files handled so far, 2: all files. */
									__(
										'%1$s of %2$s files',
										'jcore-kirjasto'
									),
									formatNumber( running.offset ),
									formatNumber( running.total )
								) }
							</p>
						</>
					) : (
						<div className="jcore-kirjasto-folders__buttons">
							<Button
								variant="secondary"
								size="small"
								disabled={ !! running }
								onClick={ () => start( source ) }
							>
								{ __( 'Import folders', 'jcore-kirjasto' ) }
							</Button>
							<Button
								variant="tertiary"
								size="small"
								disabled={ !! running }
								onClick={ () => dismiss( source ) }
							>
								{ __( 'Not now', 'jcore-kirjasto' ) }
							</Button>
						</div>
					) }
				</div>
			) ) }
		</div>
	);
}

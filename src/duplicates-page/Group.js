import { useState } from '@wordpress/element';
import { Button, Modal, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatBytes, formatDate } from './format';

function Usage( { usage } ) {
	const [ isOpen, setIsOpen ] = useState( false );

	if ( ! usage.known ) {
		return null;
	}

	if ( ! usage.count ) {
		return (
			<p className="jcore-kirjasto-duplicates__unused">
				{ __( 'Not used anywhere', 'jcore-kirjasto' ) }
			</p>
		);
	}

	return (
		<div className="jcore-kirjasto-duplicates__usage">
			<Button
				variant="link"
				aria-expanded={ isOpen }
				onClick={ () => setIsOpen( ! isOpen ) }
			>
				{ sprintf(
					/* translators: %d: number of places a file is used in. */
					_n(
						'Used in %d place',
						'Used in %d places',
						usage.count,
						'jcore-kirjasto'
					),
					usage.count
				) }
			</Button>
			{ isOpen && (
				<ul>
					{ usage.places.map( ( place, index ) => (
						<li key={ index }>
							{ place.url ? (
								<a href={ place.url }>{ place.title }</a>
							) : (
								place.title
							) }
							<span className="jcore-kirjasto-duplicates__place">
								{ [ place.type, ...place.contexts ]
									.filter( Boolean )
									.join( ' · ' ) }
							</span>
						</li>
					) ) }
					{ usage.hidden > 0 && (
						<li>
							{ sprintf(
								/* translators: %d: number of places the current user may not see. */
								_n(
									'%d item you do not have access to',
									'%d items you do not have access to',
									usage.hidden,
									'jcore-kirjasto'
								),
								usage.hidden
							) }
						</li>
					) }
				</ul>
			) }
		</div>
	);
}

function Entry( { entry, isKept, isSuggested, groupId, onKeep } ) {
	return (
		<li
			className={
				'jcore-kirjasto-duplicates__entry' +
				( isKept ? ' is-kept' : '' )
			}
		>
			<a
				className="jcore-kirjasto-duplicates__preview"
				href={ entry.url }
				target="_blank"
				rel="noreferrer"
			>
				<img src={ entry.thumb } alt="" loading="lazy" />
			</a>
			<div className="jcore-kirjasto-duplicates__keep">
				<input
					type="radio"
					id={ `keep-${ groupId }-${ entry.id }` }
					name={ `keep-${ groupId }` }
					checked={ isKept }
					onChange={ () => onKeep( entry.id ) }
				/>
				<label htmlFor={ `keep-${ groupId }-${ entry.id }` }>
					{ __( 'Keep this one', 'jcore-kirjasto' ) }
				</label>
			</div>
			{ isSuggested && (
				<span className="jcore-kirjasto-duplicates__suggested">
					{ __( 'Suggested', 'jcore-kirjasto' ) }
				</span>
			) }
			<a
				className="jcore-kirjasto-duplicates__title"
				href={ entry.edit_url }
			>
				{ entry.title || entry.filename }
			</a>
			<dl className="jcore-kirjasto-duplicates__details">
				<dt>{ __( 'File', 'jcore-kirjasto' ) }</dt>
				<dd>{ entry.filename }</dd>
				{ entry.width > 0 && (
					<>
						<dt>{ __( 'Dimensions', 'jcore-kirjasto' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: 1: width, 2: height, both in pixels. */
								__( '%1$d × %2$d', 'jcore-kirjasto' ),
								entry.width,
								entry.height
							) }
						</dd>
					</>
				) }
				{ entry.filesize > 0 && (
					<>
						<dt>{ __( 'Size', 'jcore-kirjasto' ) }</dt>
						<dd>{ formatBytes( entry.filesize ) }</dd>
					</>
				) }
				{ entry.date && (
					<>
						<dt>{ __( 'Uploaded', 'jcore-kirjasto' ) }</dt>
						<dd>{ formatDate( entry.date ) }</dd>
					</>
				) }
				{ entry.folder && (
					<>
						<dt>{ __( 'Folder', 'jcore-kirjasto' ) }</dt>
						<dd>{ entry.folder }</dd>
					</>
				) }
				{ entry.languages.length > 0 && (
					<>
						<dt>{ __( 'Languages', 'jcore-kirjasto' ) }</dt>
						<dd>{ entry.languages.join( ', ' ) }</dd>
					</>
				) }
			</dl>
			<Usage usage={ entry.usage } />
		</li>
	);
}

function Confirm( { keep, others, onConfirm, onCancel } ) {
	const used = others.filter( ( entry ) => entry.usage.count > 0 );
	const places = used.reduce( ( sum, entry ) => sum + entry.usage.count, 0 );

	return (
		<Modal
			title={ __( 'Remove the duplicates?', 'jcore-kirjasto' ) }
			onRequestClose={ onCancel }
			size="medium"
		>
			<p>
				{ sprintf(
					/* translators: 1: number of files, 2: title of the file that is kept. */
					_n(
						'%1$d file will be deleted, and “%2$s” kept.',
						'%1$d files will be deleted, and “%2$s” kept.',
						others.length,
						'jcore-kirjasto'
					),
					others.length,
					keep.title || keep.filename
				) }
			</p>
			{ places > 0 && (
				<p>
					{ sprintf(
						/* translators: %d: number of places. */
						_n(
							'%d place will be changed to use the kept file.',
							'%d places will be changed to use the kept file.',
							places,
							'jcore-kirjasto'
						),
						places
					) }
				</p>
			) }
			<p>
				{ __(
					'A file still used where it cannot be changed, such as in theme code, is kept.',
					'jcore-kirjasto'
				) }
			</p>
			<div className="jcore-kirjasto-duplicates__dialog-actions">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'jcore-kirjasto' ) }
				</Button>
				<Button variant="primary" isDestructive onClick={ onConfirm }>
					{ __( 'Remove duplicates', 'jcore-kirjasto' ) }
				</Button>
			</div>
		</Modal>
	);
}

export default function Group( {
	group,
	canMerge,
	mergeNote,
	onMerge,
	onDismiss,
} ) {
	const [ keep, setKeep ] = useState( group.suggest );
	const [ isConfirming, setIsConfirming ] = useState( false );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const kept = group.entries.find( ( entry ) => entry.id === keep );
	const others = group.entries.filter( ( entry ) => entry.id !== keep );
	const canDelete = others.every( ( entry ) => entry.can_delete );
	const groupId = group.entries[ 0 ].id;

	const run = async ( action ) => {
		setIsBusy( true );
		setError( null );
		try {
			await action();
		} catch ( caught ) {
			setError(
				caught?.message ||
					__( 'Something went wrong.', 'jcore-kirjasto' )
			);
			setIsBusy( false );
		}
	};

	return (
		<section className="jcore-kirjasto-duplicates__group">
			<header>
				<h2>
					{ 'identical' === group.kind
						? __( 'Identical files', 'jcore-kirjasto' )
						: __( 'Images that look the same', 'jcore-kirjasto' ) }
				</h2>
				{ 'similar' === group.kind && (
					<p className="description">
						{ __(
							'Compare them before removing any: these can differ in size, quality or small details.',
							'jcore-kirjasto'
						) }
					</p>
				) }
			</header>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<ul className="jcore-kirjasto-duplicates__entries">
				{ group.entries.map( ( entry ) => (
					<Entry
						key={ entry.id }
						entry={ entry }
						groupId={ groupId }
						isKept={ entry.id === keep }
						isSuggested={ entry.id === group.suggest }
						onKeep={ setKeep }
					/>
				) ) }
			</ul>

			<div className="jcore-kirjasto-duplicates__actions">
				<Button
					variant="primary"
					disabled={ ! canMerge || ! canDelete || isBusy }
					isBusy={ isBusy }
					onClick={ () => setIsConfirming( true ) }
				>
					{ __(
						'Keep the selected file, remove the others',
						'jcore-kirjasto'
					) }
				</Button>
				<Button
					variant="secondary"
					disabled={ isBusy }
					onClick={ () =>
						run( () =>
							onDismiss(
								group.entries.map( ( entry ) => entry.id )
							)
						)
					}
				>
					{ __( 'Not duplicates', 'jcore-kirjasto' ) }
				</Button>
				{ ! canMerge && mergeNote && (
					<p className="description">{ mergeNote }</p>
				) }
			</div>

			{ isConfirming && (
				<Confirm
					keep={ kept }
					others={ others }
					onCancel={ () => setIsConfirming( false ) }
					onConfirm={ () => {
						setIsConfirming( false );
						run( () =>
							onMerge(
								keep,
								others.map( ( entry ) => entry.id )
							)
						);
					} }
				/>
			) }
		</section>
	);
}

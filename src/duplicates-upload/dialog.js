import { createRoot, useState } from '@wordpress/element';
import { Button, Modal, RadioControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * The dialog shown when files about to be uploaded are already in the media
 * library. For each file the user picks between the existing file and
 * uploading anyway; where there is nothing to pick a file for, between
 * skipping it and uploading anyway.
 */
const settings = window.jcoreKirjastoDuplicates || {};

function Match( { match } ) {
	const text =
		'identical' === match.kind
			? __( 'The same file is already in the library:', 'jcore-kirjasto' )
			: __(
					'An image that looks the same is already in the library:',
					'jcore-kirjasto'
			  );

	return (
		<div className="jcore-kirjasto-upload-check__match">
			{ match.thumb && (
				<img
					className="jcore-kirjasto-upload-check__thumb"
					src={ match.thumb }
					alt=""
				/>
			) }
			<div>
				<p>{ text }</p>
				<p>
					<a href={ match.edit_url } target="_blank" rel="noreferrer">
						{ match.title || match.filename }
					</a>
					{ match.width > 0 && (
						<span className="jcore-kirjasto-upload-check__meta">
							{ sprintf(
								/* translators: 1: width, 2: height, both in pixels. */
								__( '%1$d × %2$d', 'jcore-kirjasto' ),
								match.width,
								match.height
							) }
						</span>
					) }
				</p>
			</div>
		</div>
	);
}

function UploadCheck( { items, canSelect, onDone } ) {
	const initial = canSelect ? 'existing' : 'skip';
	const [ choices, setChoices ] = useState( () =>
		items.map( () => initial )
	);

	const options = [
		canSelect
			? {
					label: __(
						'Use the file in the library',
						'jcore-kirjasto'
					),
					value: 'existing',
			  }
			: {
					label: __( 'Skip this file', 'jcore-kirjasto' ),
					value: 'skip',
			  },
		{ label: __( 'Upload anyway', 'jcore-kirjasto' ), value: 'upload' },
	];

	return (
		<Modal
			title={ _n(
				'This file is already in the media library',
				'These files are already in the media library',
				items.length,
				'jcore-kirjasto'
			) }
			className="jcore-kirjasto-upload-check"
			overlayClassName="jcore-kirjasto-upload-check__overlay"
			onRequestClose={ () => onDone( choices ) }
			shouldCloseOnClickOutside={ false }
		>
			<ul className="jcore-kirjasto-upload-check__items">
				{ items.map( ( item, index ) => (
					<li
						key={ index }
						className="jcore-kirjasto-upload-check__item"
					>
						<h2 className="jcore-kirjasto-upload-check__name">
							{ item.name }
						</h2>
						<Match match={ item.matches[ 0 ] } />
						<RadioControl
							hideLabelFromVision
							label={ sprintf(
								/* translators: %s: file name. */
								__( 'What to do with %s', 'jcore-kirjasto' ),
								item.name
							) }
							selected={ choices[ index ] }
							options={ options }
							onChange={ ( value ) =>
								setChoices( ( current ) =>
									current.map( ( choice, i ) =>
										i === index ? value : choice
									)
								)
							}
						/>
					</li>
				) ) }
			</ul>
			<div className="jcore-kirjasto-upload-check__footer">
				{ settings.reviewUrl && (
					<a
						href={ settings.reviewUrl }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Review all duplicates', 'jcore-kirjasto' ) }
					</a>
				) }
				<Button variant="primary" onClick={ () => onDone( choices ) }>
					{ __( 'Continue', 'jcore-kirjasto' ) }
				</Button>
			</div>
		</Modal>
	);
}

// One dialog at a time: the block editor may upload several files at once.
let queue = Promise.resolve();

/**
 * Asks what to do with files that are already in the library.
 *
 * @param {Object[]} items     `name` and `matches` of each file.
 * @param {boolean}  canSelect Whether the existing files can be used instead.
 * @return {Promise<string[]>} For each file `existing`, `skip` or `upload`.
 */
export function ask( items, canSelect ) {
	const answer = queue.then(
		() =>
			new Promise( ( resolve ) => {
				const element = document.createElement( 'div' );
				document.body.appendChild( element );
				const root = createRoot( element );

				root.render(
					<UploadCheck
						items={ items }
						canSelect={ canSelect }
						onDone={ ( choices ) => {
							root.unmount();
							element.remove();
							resolve( choices );
						} }
					/>
				);
			} )
	);

	queue = answer.catch( () => {} );

	return answer;
}

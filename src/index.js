/**
 * Blocksmith editor sidebar.
 *
 * A minimal PluginSidebar that calls the blocksmith/generate-layout ability
 * over the Abilities REST endpoint and inserts the returned blocks into the
 * editor. All the intelligence lives server-side in the ability; this is just
 * the UI surface.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import {
	PanelBody,
	TextareaControl,
	SelectControl,
	Button,
	Notice,
	Spinner,
	Flex,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { parse } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const SIDEBAR_NAME = 'blocksmith-sidebar';
const SIDEBAR_ICON = 'layout';

const TONE_OPTIONS = [
	{ label: __( 'Professional', 'blocksmith' ), value: 'professional' },
	{ label: __( 'Casual', 'blocksmith' ), value: 'casual' },
	{ label: __( 'Creative', 'blocksmith' ), value: 'creative' },
	{ label: __( 'Minimal', 'blocksmith' ), value: 'minimal' },
	{ label: __( 'Bold', 'blocksmith' ), value: 'bold' },
];

function BlocksmithSidebar() {
	const [ prompt, setPrompt ] = useState( '' );
	const [ tone, setTone ] = useState( 'professional' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const { insertBlocks } = useDispatch( blockEditorStore );
	const postTitle = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'title' ),
		[]
	);

	const onGenerate = async () => {
		const trimmed = prompt.trim();
		if ( ! trimmed ) {
			setNotice( {
				status: 'warning',
				message: __( 'Describe what you want to build first.', 'blocksmith' ),
			} );
			return;
		}

		setIsLoading( true );
		setNotice( null );

		try {
			const result = await apiFetch( {
				path: '/wp-abilities/v1/abilities/blocksmith/generate-layout/run',
				method: 'POST',
				data: {
					input: {
						prompt: trimmed,
						tone,
						...( postTitle ? { postTitle } : {} ),
					},
				},
			} );

			if ( ! result?.blockMarkup ) {
				throw new Error( __( 'No layout was returned.', 'blocksmith' ) );
			}

			const blocks = parse( result.blockMarkup );
			if ( ! blocks.length ) {
				throw new Error( __( 'The generated layout could not be parsed.', 'blocksmith' ) );
			}

			insertBlocks( blocks );

			const warning = result.warnings?.length
				? ' ' +
				  __( 'Unrecognized blocks were skipped:', 'blocksmith' ) +
				  ' ' +
				  result.warnings.join( ', ' )
				: '';

			setNotice( {
				status: 'success',
				message:
					( result.summary || __( 'Layout inserted.', 'blocksmith' ) ) + warning,
			} );
			setPrompt( '' );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error?.message || __( 'Generation failed. Check your AI Connector.', 'blocksmith' ),
			} );
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME } icon={ SIDEBAR_ICON }>
				{ __( 'Blocksmith', 'blocksmith' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name={ SIDEBAR_NAME }
				icon={ SIDEBAR_ICON }
				title={ __( 'Blocksmith', 'blocksmith' ) }
			>
				<PanelBody>
					<p>
						{ __(
							'Describe a section or page and Blocksmith will build it using your theme’s blocks and styles.',
							'blocksmith'
						) }
					</p>

					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'What should we build?', 'blocksmith' ) }
						placeholder={ __(
							'e.g. A hero with a heading and CTA button, then a three-column features section.',
							'blocksmith'
						) }
						value={ prompt }
						onChange={ setPrompt }
						rows={ 5 }
						disabled={ isLoading }
					/>

					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Tone', 'blocksmith' ) }
						value={ tone }
						options={ TONE_OPTIONS }
						onChange={ setTone }
						disabled={ isLoading }
					/>

					<Button
						variant="primary"
						onClick={ onGenerate }
						disabled={ isLoading }
						style={ { marginTop: '12px' } }
					>
						{ isLoading ? (
							<Flex justify="center" gap={ 2 }>
								<Spinner />
								{ __( 'Generating…', 'blocksmith' ) }
							</Flex>
						) : (
							__( 'Generate layout', 'blocksmith' )
						) }
					</Button>

					{ notice && (
						<Notice
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
							politeness="assertive"
						>
							{ notice.message }
						</Notice>
					) }
				</PanelBody>
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'blocksmith', { render: BlocksmithSidebar } );

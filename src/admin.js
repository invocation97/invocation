/**
 * Blocksmith admin app — the Site Brief editor.
 *
 * Loads/saves the brief via the REST settings endpoint and can generate it from
 * the site's own content via the gather-site-context ability.
 */

import { createRoot, useState, useEffect } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	__experimentalHeading as Heading,
	TextareaControl,
	FormTokenField,
	Button,
	Notice,
	Spinner,
	Flex,
	FlexItem,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const OPTION = 'blocksmith_site_brief';

const DEFAULT_BRIEF = {
	purpose: '',
	audience: '',
	toneVoice: '',
	offerings: [],
	keyTerms: [],
	avoid: [],
	generatedAt: '',
};

function SiteBriefApp() {
	const [ brief, setBrief ] = useState( DEFAULT_BRIEF );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/wp/v2/settings' } )
			.then( ( settings ) => {
				setBrief( { ...DEFAULT_BRIEF, ...( settings[ OPTION ] || {} ) } );
			} )
			.catch( ( e ) => setNotice( { status: 'error', message: e.message } ) )
			.finally( () => setIsLoading( false ) );
	}, [] );

	const update = ( key, value ) =>
		setBrief( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const save = async () => {
		setIsSaving( true );
		setNotice( null );
		try {
			await apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { [ OPTION ]: brief },
			} );
			setNotice( { status: 'success', message: __( 'Site Brief saved.', 'blocksmith' ) } );
		} catch ( e ) {
			setNotice( { status: 'error', message: e.message } );
		} finally {
			setIsSaving( false );
		}
	};

	const generate = async () => {
		setIsGenerating( true );
		setNotice( null );
		try {
			const result = await apiFetch( {
				path: '/wp-abilities/v1/abilities/blocksmith/gather-site-context/run',
				method: 'POST',
				data: { input: {} },
			} );
			setBrief( { ...DEFAULT_BRIEF, ...result } );
			setNotice( {
				status: 'success',
				message: __( 'Generated from your site and saved. Review and edit as needed.', 'blocksmith' ),
			} );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message: e.message || __( 'Generation failed. Check your AI Connector.', 'blocksmith' ),
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	if ( isLoading ) {
		return (
			<Flex justify="flex-start" gap={ 2 } style={ { padding: '24px 0' } }>
				<Spinner />
				{ __( 'Loading…', 'blocksmith' ) }
			</Flex>
		);
	}

	return (
		<div style={ { maxWidth: '760px' } }>
			<Flex justify="space-between" align="center" style={ { margin: '16px 0' } }>
				<FlexItem>
					<Heading level={ 1 }>{ __( 'Blocksmith — Site Brief', 'blocksmith' ) }</Heading>
				</FlexItem>
				<FlexItem>
					<Button variant="secondary" onClick={ generate } disabled={ isGenerating || isSaving }>
						{ isGenerating ? (
							<Flex gap={ 2 } justify="center">
								<Spinner />
								{ __( 'Analyzing…', 'blocksmith' ) }
							</Flex>
						) : (
							__( 'Generate from my site', 'blocksmith' )
						) }
					</Button>
				</FlexItem>
			</Flex>

			<p>
				{ __(
					'The Site Brief grounds every Blocksmith generation in your site’s purpose, audience and voice. Generate it from your content, then edit anything.',
					'blocksmith'
				) }
			</p>

			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<Heading level={ 3 }>{ __( 'Brand', 'blocksmith' ) }</Heading>
				</CardHeader>
				<CardBody>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Purpose', 'blocksmith' ) }
						help={ __( 'What this site is for.', 'blocksmith' ) }
						value={ brief.purpose }
						onChange={ ( v ) => update( 'purpose', v ) }
						rows={ 2 }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Audience', 'blocksmith' ) }
						value={ brief.audience }
						onChange={ ( v ) => update( 'audience', v ) }
						rows={ 2 }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Voice & tone', 'blocksmith' ) }
						value={ brief.toneVoice }
						onChange={ ( v ) => update( 'toneVoice', v ) }
						rows={ 2 }
					/>
				</CardBody>
			</Card>

			<Card style={ { marginTop: '16px' } }>
				<CardHeader>
					<Heading level={ 3 }>{ __( 'Content guidance', 'blocksmith' ) }</Heading>
				</CardHeader>
				<CardBody>
					<FormTokenField
						__nextHasNoMarginBottom
						label={ __( 'Offerings (products, services, topics)', 'blocksmith' ) }
						value={ brief.offerings }
						onChange={ ( v ) => update( 'offerings', v ) }
					/>
					<FormTokenField
						__nextHasNoMarginBottom
						label={ __( 'Preferred terms', 'blocksmith' ) }
						value={ brief.keyTerms }
						onChange={ ( v ) => update( 'keyTerms', v ) }
					/>
					<FormTokenField
						__nextHasNoMarginBottom
						label={ __( 'Avoid', 'blocksmith' ) }
						value={ brief.avoid }
						onChange={ ( v ) => update( 'avoid', v ) }
					/>
				</CardBody>
			</Card>

			<Flex justify="flex-start" style={ { marginTop: '16px' } }>
				<Button variant="primary" onClick={ save } disabled={ isSaving || isGenerating }>
					{ isSaving ? __( 'Saving…', 'blocksmith' ) : __( 'Save changes', 'blocksmith' ) }
				</Button>
			</Flex>

			{ brief.generatedAt && (
				<p style={ { color: '#757575', marginTop: '12px' } }>
					{ __( 'Last generated:', 'blocksmith' ) } { brief.generatedAt }
				</p>
			) }
		</div>
	);
}

const root = document.getElementById( 'blocksmith-admin-root' );
if ( root ) {
	createRoot( root ).render( <SiteBriefApp /> );
}

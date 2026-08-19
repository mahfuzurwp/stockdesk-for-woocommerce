/**
 * In-app sidebar navigation.
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	IconChecklist,
	IconFileImport,
	IconFileExport,
	IconChevronLeft,
	IconChevronRight,
} from '@tabler/icons-react';
import { PATHS, getPageTitle, navigate } from '../routing';

const SIDEBAR_COLLAPSED_KEY = 'sdfw_sidebar_collapsed';

const NAV_ITEMS = [
	{
		path: PATHS.inventory,
		Icon: IconChecklist,
		iconSize: 24,
	},
	{
		path: PATHS.import,
		Icon: IconFileImport,
		iconSize: 20,
	},
	{
		path: PATHS.export,
		Icon: IconFileExport,
		iconSize: 20,
	},
];

function readCollapsedPreference() {
	try {
		return window.localStorage.getItem( SIDEBAR_COLLAPSED_KEY ) === '1';
	} catch {
		return false;
	}
}

export default function SidebarNav( { currentPath } ) {
	const [ collapsed, setCollapsed ] = useState( readCollapsedPreference );
	const logoUrl = window.sdfwAdmin?.logoUrl || '';
	const version = window.sdfwAdmin?.version || '';
	const supportUrl =
		window.sdfwAdmin?.supportUrl ||
		'https://wordpress.org/support/plugin/stockdesk-for-woocommerce/';
	const reviewUrl =
		window.sdfwAdmin?.reviewUrl ||
		'https://wordpress.org/support/plugin/stockdesk-for-woocommerce/reviews/#new-post';

	useEffect( () => {
		try {
			window.localStorage.setItem(
				SIDEBAR_COLLAPSED_KEY,
				collapsed ? '1' : '0'
			);
		} catch {
			// Storage unavailable; preference won't persist.
		}
	}, [ collapsed ] );

	const toggleCollapsed = () => {
		setCollapsed( ( value ) => ! value );
	};

	return (
		<aside
			className={
				'sdfw-shell__sidebar' + ( collapsed ? ' is-collapsed' : '' )
			}
		>
			<button
				type="button"
				className="sdfw-shell__sidebar-edge-toggle"
				onClick={ toggleCollapsed }
				aria-expanded={ ! collapsed }
				aria-controls="sdfw-shell-nav"
				title={
					collapsed
						? __( 'Expand sidebar', 'stockdesk-for-woocommerce' )
						: __( 'Collapse sidebar', 'stockdesk-for-woocommerce' )
				}
			>
				{ collapsed ? (
					<IconChevronRight size={ 14 } stroke={ 2.25 } />
				) : (
					<IconChevronLeft size={ 14 } stroke={ 2.25 } />
				) }
			</button>

			<div className="sdfw-shell__sidebar-inner">
				<div className="sdfw-shell__sidebar-brand">
					<div className="sdfw-shell__brand">
						{ logoUrl ? (
							<img
								className="sdfw-shell__logo"
								src={ logoUrl }
								alt=""
								width={ 32 }
								height={ 32 }
							/>
						) : null }
						<span
							className="sdfw-shell__brand-name"
							aria-hidden={ collapsed ? 'true' : undefined }
						>
							{ __( 'StockDesk', 'stockdesk-for-woocommerce' ) }
						</span>
					</div>
				</div>

				<nav
					id="sdfw-shell-nav"
					className="sdfw-shell__nav"
					aria-label={ __( 'StockDesk', 'stockdesk-for-woocommerce' ) }
				>
					{ NAV_ITEMS.map( ( item ) => {
						const isActive = currentPath === item.path;
						const label = getPageTitle( item.path );
						const { Icon, iconSize } = item;
						return (
							<button
								key={ item.path }
								type="button"
								className={
									'sdfw-shell__nav-item' +
									( isActive ? ' is-active' : '' )
								}
								aria-current={ isActive ? 'page' : undefined }
								title={ collapsed ? label : undefined }
								onClick={ () => navigate( item.path ) }
							>
								<span
									className="sdfw-shell__nav-icon"
									aria-hidden="true"
								>
									<Icon size={ iconSize } stroke={ 1.75 } />
								</span>
								<span
									className="sdfw-shell__nav-label"
									aria-hidden={ collapsed ? 'true' : undefined }
								>
									{ label }
								</span>
							</button>
						);
					} ) }
				</nav>

				<div className="sdfw-shell__sidebar-footer">
					{ version && (
						<p className="sdfw-shell__version">
							{ __( 'StockDesk', 'stockdesk-for-woocommerce' ) }{ ' ' }
							v{ version }
						</p>
					) }
					<a
						className="sdfw-shell__footer-link"
						href={ supportUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Get support', 'stockdesk-for-woocommerce' ) }
					</a>
					<a
						className="sdfw-shell__footer-link"
						href={ reviewUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __(
							'Enjoying StockDesk? Leave a review',
							'stockdesk-for-woocommerce'
						) }
					</a>
				</div>
			</div>
		</aside>
	);
}

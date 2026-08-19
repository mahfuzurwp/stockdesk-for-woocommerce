/**
 * StockDesk app chrome: sidebar + content header + content.
 */

import SidebarNav from './SidebarNav';
import { getPageTitle } from '../routing';

export default function AppShell( { currentPath, children } ) {
	const pageTitle = getPageTitle( currentPath );

	return (
		<div className="sdfw-shell">
			<div className="sdfw-shell__body">
				<SidebarNav currentPath={ currentPath } />

				<div className="sdfw-shell__main">
					<header className="sdfw-shell__header">
						<h1 className="sdfw-shell__page-title">{ pageTitle }</h1>
					</header>

					<main className="sdfw-shell__content">{ children }</main>
				</div>
			</div>
		</div>
	);
}

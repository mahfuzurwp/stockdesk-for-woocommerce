/**
 * Page content wrapper (no screen heading — shell provides chrome).
 */

const Page = ( { children, className = '' } ) => {
	return (
		<div
			className={
				'sdfw-page' + ( className ? ` ${ className }` : '' )
			}
		>
			<div className="sdfw-page__content">{ children }</div>
		</div>
	);
};

export default Page;

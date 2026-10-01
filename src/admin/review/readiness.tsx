/**
 * What stands between this campaign and approval, at the top of the page.
 *
 * Every check and every sentence comes from the server, which runs the same
 * validation the approval guard runs (`Approval_Readiness`). Nothing is
 * decided here: a list that said "ready" from browser logic could disagree
 * with the guard, and the guard is the one that counts.
 *
 * A passed check is a quiet tick so the eye goes to what is blocked; a blocked
 * one carries the reasons in the validator's own words. The verdict is a pill
 * with a word, and each check's state is also announced in words, so nothing
 * depends on the tick or the colour.
 */

import type { ReactElement } from 'react';
import { t } from '../shared/save';
import type { Readiness as ReadinessData } from './types';

function Mark( { ok }: { ok: boolean } ): ReactElement {
	return (
		<span
			className={
				ok
					? 'aggr-approval__mark aggr-approval__mark--ok'
					: 'aggr-approval__mark aggr-approval__mark--blocked'
			}
			aria-hidden="true"
		>
			<svg
				viewBox="0 0 24 24"
				width="14"
				height="14"
				fill="none"
				stroke="currentColor"
				strokeWidth={ 2.5 }
				strokeLinecap="round"
				strokeLinejoin="round"
				focusable="false"
			>
				{ ok ? (
					<path d="M5 12.5l4.5 4.5L19 7.5" />
				) : (
					<path d="M12 6.5v7M12 17.5h.01" />
				) }
			</svg>
		</span>
	);
}

export function Readiness( {
	readiness,
}: {
	readiness: ReadinessData;
} ): ReactElement {
	const blocked = readiness.checks.filter( ( check ) => ! check.ok ).length;

	return (
		<section className="aggr-panel" aria-labelledby="aggr-approval-heading">
			<div className="aggr-approval__head">
				<h2 id="aggr-approval-heading" className="aggr-panel__head">
					{ t( 'readinessTitle' ) }
				</h2>
				<span
					className={
						readiness.ready
							? 'aggr-state aggr-state--live'
							: 'aggr-state aggr-state--attention'
					}
				>
					{ readiness.ready
						? t( 'readyToApprove' )
						: ( 1 === blocked
								? t( 'blockedOne' )
								: t( 'blockedMany' )
						  ).replace( '%d', String( blocked ) ) }
				</span>
			</div>
			<ul className="aggr-approval__list">
				{ readiness.checks.map( ( check ) => (
					<li
						key={ check.key }
						className={
							check.ok
								? 'aggr-approval__check'
								: 'aggr-approval__check aggr-approval__check--blocked'
						}
					>
						<Mark ok={ check.ok } />
						<div className="aggr-approval__body">
							<span className="aggr-approval__label">
								{ check.label }
								<span className="screen-reader-text">
									{ check.ok
										? t( 'checkPassed' )
										: t( 'checkBlocked' ) }
								</span>
							</span>
							{ check.problems.length > 0 ? (
								<ul className="aggr-approval__problems">
									{ check.problems.map( ( problem ) => (
										<li key={ problem }>{ problem }</li>
									) ) }
								</ul>
							) : null }
						</div>
					</li>
				) ) }
			</ul>
		</section>
	);
}

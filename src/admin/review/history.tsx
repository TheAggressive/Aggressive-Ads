/**
 * What reviewers decided about one revision, newest first.
 *
 * The same list the advertiser sees, plus who decided. That name stays off
 * the portal payload: the advertiser is owed the decision and the reason,
 * and the reviewer's identity is staff information.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx.
 */

import type { ReactElement } from 'react';
import { t } from '../shared/save';
import type { CreativeDecision } from './types';

const WORDS: Record< string, string > = {
	approved: 'decisionApproved',
	rejected: 'decisionRejected',
	changes_approved: 'decisionUpdateApproved',
	changes_rejected: 'decisionUpdateRejected',
};

/** The decisions already made about this revision, or nothing when there are none. */
export function DecisionHistory( {
	decisions,
}: {
	decisions: CreativeDecision[] | undefined;
} ): ReactElement | null {
	if ( ! decisions || 0 === decisions.length ) {
		return null;
	}

	return (
		<div className="aggr-ad-history">
			<p className="aggr-ad-history__title">{ t( 'reviewHistory' ) }</p>
			<ul className="aggr-ad-history__list">
				{ decisions.map( ( row, index ) => {
					const refused =
						'rejected' === row.decision ||
						'changes_rejected' === row.decision;

					return (
						<li
							className="aggr-ad-history__item"
							key={ `${ row.decision }-${ row.at }-${ index }` }
						>
							<span
								className={ `aggr-pill ${
									refused
										? 'aggr-pill--danger'
										: 'aggr-pill--live'
								}` }
							>
								{ t( WORDS[ row.decision ] ?? 'reviewed' ) }
							</span>
							<time
								dateTime={
									row.at > 0
										? new Date(
												row.at * 1000
										  ).toISOString()
										: undefined
								}
							>
								{ row.at_text }
							</time>
							{ '' !== row.actor ? (
								<span>{ row.actor }</span>
							) : null }
							{ '' !== row.reason ? (
								<p className="aggr-ad-history__reason">
									{ row.reason }
								</p>
							) : null }
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}

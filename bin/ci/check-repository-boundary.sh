#!/usr/bin/env bash
#
# Architecture boundary gate.
#
# Two rules, both from docs/architecture.md, both enforced here because a
# convention that is only written down erodes:
#
#   1. inc/Repository/ is the only place data access appears.
#   2. inc/Integration/Adsanity/ is the only place AdSanity exists.
#
# The second one is the expensive one to get wrong. AdSanity is a third-party
# plugin whose meta keys are undocumented implementation detail read out of its
# source. When it changes, the blast radius must be one directory — scatter
# get_post_meta( $id, '_start_date' ) through the codebase and every future
# AdSanity release becomes a full-repository audit.

set -euo pipefail

cd "$(dirname "$0")/../.."

status=0

# --- Rule 1: data access lives in inc/Repository/ ---------------------------

DATA_ACCESS_PATTERN='(\$wpdb\b|\bnew[[:space:]]+WP_Query\b|\bget_posts[[:space:]]*\(|\bget_post_meta[[:space:]]*\(|\badd_post_meta[[:space:]]*\(|\bupdate_post_meta[[:space:]]*\(|\bdelete_post_meta[[:space:]]*\(|\bget_post_meta[[:space:]]*\()'

data_hits=$(
	grep -rInE "$DATA_ACCESS_PATTERN" inc \
		--include='*.php' \
		--exclude-dir=Repository \
		2>/dev/null || true
)

if [ -n "$data_hits" ]; then
	echo "Data access outside inc/Repository/:" >&2
	echo "$data_hits" >&2
	echo >&2
	status=1
fi

# --- Rule 2: AdSanity lives in inc/Integration/Adsanity/ --------------------
#
# What is being caught is AdSanity's *API surface* — its constants, classes,
# hooks, taxonomy, post type and meta keys — not the word "AdSanity". Two
# things must not trip this gate:
#
#   - Prose. A comment explaining why approval fails when AdSanity is inactive
#     belongs wherever that logic lives.
#   - Our own vocabulary. `laao_ads_publish_to_adsanity` is a capability this
#     plugin invents; it names the provider without touching it. Matching the
#     bare substring flagged it, which is how a guard trains people to
#     work around the guard.
#
# So every pattern is anchored to a real identifier boundary. Meta keys are
# matched as quoted string literals, so our own identically-named concepts —
# a $start_date variable, say — do not trip it either.

ADSANITY_PATTERN="(\bADSANITY_[A-Z0-9_]+|\bAdsanity\\\\|\bAdSanity_[A-Za-z0-9_]+|\badsanity_[a-z0-9_]+|'ad-group'|\"ad-group\"|'_start_date'|\"_start_date\"|'_end_date'|\"_end_date\"|'ad_src'|\"ad_src\"|'post_type'[[:space:]]*=>[[:space:]]*'ads')"

adsanity_hits=$(
	grep -rInE "$ADSANITY_PATTERN" inc \
		--include='*.php' \
		2>/dev/null | grep -v '^inc/Integration/Adsanity/' || true
)

if [ -n "$adsanity_hits" ]; then
	echo "AdSanity identifiers outside inc/Integration/Adsanity/:" >&2
	echo "$adsanity_hits" >&2
	echo >&2
	status=1
fi

# --- Rule 3: inc/Domain/ calls no WordPress ---------------------------------
#
# The domain layer's purity is what makes the campaign rules testable in
# milliseconds with no bootstrap — which is what makes it affordable to test
# them exhaustively, all 121 status pairs rather than the handful anyone
# remembers. One get_option() in here and that property is gone, and the
# exhaustive test becomes an integration test nobody runs on every save.
#
# Referencing another class's constants is fine; calling a WordPress function
# is not.

if [ -d inc/Domain ]; then
	WORDPRESS_CALL_PATTERN='(\b(apply_filters|do_action|add_action|add_filter|current_user_can|get_option|update_option|get_userdata|get_post|wp_[a-z_]+|esc_[a-z]+|sanitize_[a-z_]+|__|_e|_n|_x|is_wp_error|absint)[[:space:]]*\()'

	domain_hits=$(
		grep -rInE "$WORDPRESS_CALL_PATTERN" inc/Domain --include='*.php' 2>/dev/null || true
	)

	if [ -n "$domain_hits" ]; then
		echo "WordPress called from inc/Domain/:" >&2
		echo "$domain_hits" >&2
		echo >&2
		status=1
	fi
fi

if [ "$status" -ne 0 ]; then
	echo "See docs/architecture.md for why these boundaries exist." >&2
	exit 1
fi

echo "check-repository-boundary: ok"

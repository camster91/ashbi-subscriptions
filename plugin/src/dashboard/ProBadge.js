/**
 * Availability marker for functionality not included in this distribution.
 *
 * The admin's own pill (assets/css/admin-components/badges.css), with the same
 * Existing CSS classes remain unchanged for compatibility.
 */

import { __ } from "@wordpress/i18n";

export default function ProBadge() {
  return (
    <span
      className="wpsubs-badge wpsubs-badge--pro subscrpt-pro-badge"
      title={__("Not included in this build", "subscription")}
    >
      {__("Unavailable", "subscription")}
    </span>
  );
}

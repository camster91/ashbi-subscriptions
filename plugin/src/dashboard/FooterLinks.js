/**
 * The centred link row at the very bottom.
 */

import Icon from "./Icon";

export default function FooterLinks({ links }) {
  return (
    <p className="subscrpt-footer">
      {links.map((link, index) => (
        <span key={link.url}>
          {index > 0 && (
            <span className="subscrpt-footer__sep" aria-hidden="true">
              ·
            </span>
          )}
          <a
            href={link.url}
            target={link.external ? "_blank" : undefined}
            rel={link.external ? "noreferrer noopener" : undefined}
          >
            {link.label}
            {link.external ? <Icon name="external" size={11} /> : null}
          </a>
        </span>
      ))}
    </p>
  );
}

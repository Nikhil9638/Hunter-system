import { Link } from 'react-router-dom';
import { PageHeader } from '../components/ui.jsx';

export function NotFoundPage() {
  return (
    <div className="narrow not-found">
      <p className="eyebrow">System notice · 404</p>
      <PageHeader title="Page not found" />
      <div className="card auth-form">
        <span className="hud-corner hud-corner-tl" aria-hidden="true" /><span className="hud-corner hud-corner-tr" aria-hidden="true" /><span className="hud-corner hud-corner-bl" aria-hidden="true" /><span className="hud-corner hud-corner-br" aria-hidden="true" />
        <p>That page does not exist. <Link to="/">Go to the start</Link>.</p>
      </div>
    </div>
  );
}

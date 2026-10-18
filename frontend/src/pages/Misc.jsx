import { Link } from 'react-router-dom';
import { PageHeader } from '../components/ui.jsx';

export function NotFoundPage() {
  return (
    <div className="narrow">
      <PageHeader title="Page not found" />
      <p>That page does not exist. <Link to="/">Go to the start</Link>.</p>
    </div>
  );
}

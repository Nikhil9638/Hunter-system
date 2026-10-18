import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ApiError, fieldErrors } from '../api/client.js';
import { useAuth } from '../auth/AuthContext.jsx';
import { useAction } from '../lib/useApi.js';
import { Notice, PageHeader } from '../components/ui.jsx';

const LOST = {
  session_expired: 'Your session ended after 30 minutes without activity. Anything the server already saved (answers, results, coins) is kept. Log in to continue.',
  session_ended: 'Your session ended (you logged out elsewhere, or the account is not active). Saved progress is kept.',
  not_authenticated: 'Please log in again. Saved progress is kept.',
};

function Field({ id, label, type = 'text', value, onChange, error, autoComplete, hint, ...rest }) {
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <input id={id} type={type} value={value} onChange={(e) => onChange(e.target.value)} autoComplete={autoComplete}
             aria-invalid={error ? 'true' : undefined} aria-describedby={[hint && `${id}-hint`, error && `${id}-err`].filter(Boolean).join(' ') || undefined}
             {...rest} />
      {hint && <p className="hint" id={`${id}-hint`}>{hint}</p>}
      {error && <p className="field-error" id={`${id}-err`}>{error}</p>}
    </div>
  );
}

const FIELD_TEXT = {
  required: 'This field is required.', invalid_format: 'Enter a valid email address.', invalid: 'This value is not valid.',
  too_short: 'Use at least 12 characters.', too_long: 'This is too long.', invalid_encoding: 'Contains characters that cannot be used.',
};

export function LoginPage() {
  const { login, lostReason } = useAuth();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState(null);
  const [pending, run] = useAction();

  const onSubmit = (e) => {
    e.preventDefault();
    setError(null);
    run(async () => {
      try {
        await login(email, password);                     // the Public guard then routes onwards (or back to "from")
      } catch (err) {
        if (err instanceof ApiError && err.code === 'too_many_attempts') {
          setError(`Too many attempts. Try again in about ${Math.ceil((err.details.retry_after_seconds ?? 900) / 60)} minutes.`);
        } else {
          setError(err.message);
        }
      }
    });
  };

  return (
    <div className="narrow">
      <PageHeader title="Log in" />
      {lostReason && LOST[lostReason] && <Notice tone="warning" title="Session ended">{LOST[lostReason]}</Notice>}
      {location.state?.from && !lostReason && <Notice tone="info">Log in to continue.</Notice>}
      <form className="card form" onSubmit={onSubmit} noValidate>
        <Field id="email" label="Email" type="email" value={email} onChange={setEmail} autoComplete="email" required />
        <Field id="password" label="Password" type="password" value={password} onChange={setPassword} autoComplete="current-password" required />
        {error && <Notice tone="error">{error}</Notice>}
        <button className="btn btn-primary btn-block" type="submit" disabled={pending}>{pending ? 'Logging in…' : 'Log in'}</button>
      </form>
      <p className="center">New here? <Link to="/register">Create an account</Link></p>
    </div>
  );
}

export function RegisterPage() {
  const { register } = useAuth();
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState({});
  const [error, setError] = useState(null);
  const [pending, run] = useAction();

  const onSubmit = (e) => {
    e.preventDefault();
    setErrors({});
    setError(null);
    run(async () => {
      try {
        await register(email, name, password);
      } catch (err) {
        const f = fieldErrors(err);
        if (Object.keys(f).length) setErrors(f);
        else if (err instanceof ApiError && err.code === 'email_taken') setErrors({ email: 'email_taken' });
        else setError(err.message);
      }
    });
  };
  const msg = (k) => (errors[k] === 'email_taken' ? 'An account with this email already exists.' : errors[k] ? FIELD_TEXT[errors[k]] ?? errors[k] : null);

  return (
    <div className="narrow">
      <PageHeader title="Create your account" />
      <form className="card form" onSubmit={onSubmit} noValidate>
        <Field id="name" label="Display name" value={name} onChange={setName} autoComplete="nickname" error={msg('display_name')} maxLength={80} required />
        <Field id="email" label="Email" type="email" value={email} onChange={setEmail} autoComplete="email" error={msg('email')} required />
        <Field id="password" label="Password" type="password" value={password} onChange={setPassword} autoComplete="new-password"
               hint="At least 12 characters. A few words with spaces works well." error={msg('password')} required />
        {error && <Notice tone="error">{error}</Notice>}
        <button className="btn btn-primary btn-block" type="submit" disabled={pending}>{pending ? 'Creating account…' : 'Create account'}</button>
      </form>
      <p className="center">Already registered? <Link to="/login">Log in</Link></p>
    </div>
  );
}

import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ApiError, get, post } from '../api/client.js';
import { Markdown } from '../lib/markdown.jsx';
import { useAction, useApi } from '../lib/useApi.js';
import { LoadError, Loading, Notice, PageHeader } from '../components/ui.jsx';

export function LessonPage() {
  const { topicId } = useParams();
  const navigate = useNavigate();
  const res = useApi((signal) => get(`/topics/${topicId}/lesson`, { signal }), [topicId]);   // the server records the visit
  const [pending, run] = useAction();
  const [error, setError] = useState(null);

  if (res.loading) return <Loading what="Loading the lesson" />;
  if (res.error) {
    const e = res.error;
    if (e instanceof ApiError && ['topic_locked', 'topic_content_not_available', 'not_found'].includes(e.code)) {
      return (
        <div className="narrow">
          <PageHeader title="Lesson unavailable" />
          <Notice tone="info">{e.code === 'topic_locked' ? 'This topic is still locked.' : e.code === 'not_found' ? 'This topic does not exist.' : 'This lesson is not available yet.'}</Notice>
          <Link to="/topics">Back to topics</Link>
        </div>
      );
    }
    return <LoadError error={e} onRetry={res.reload} />;
  }
  const lesson = res.data;
  const startQuiz = () => run(async () => {
    setError(null);
    try {
      const a = await post(`/topics/${topicId}/quiz-attempts`, { started_via: 'lesson_first' });
      navigate(`/quiz/${a.attempt_id}`);
    } catch (err) {
      setError(err.message);
    }
  });

  return (
    <article className="lesson">
      <PageHeader title={lesson.title} />
      <Markdown source={withoutRepeatedTitle(lesson.body_markdown, lesson.title)} />
      <div className="lesson-end card">
        <p>Ready? The quiz has 5 questions; 3 correct passes the topic.</p>
        <button type="button" className="btn btn-primary" onClick={startQuiz} disabled={pending}>{pending ? 'Starting…' : 'Start the quiz'}</button>
        {' '}<Link to={`/topics/${topicId}`}>Back to the topic</Link>
        {error && <Notice tone="error">{error}</Notice>}
      </div>
    </article>
  );
}

/** Lessons often start with "# <title>"; the page already shows the title as its h1. */
function withoutRepeatedTitle(body, title) {
  const lines = String(body ?? '').split(/\r?\n/);
  return lines[0]?.trim() === `# ${title}`.trim() ? lines.slice(1).join('\n') : body;
}

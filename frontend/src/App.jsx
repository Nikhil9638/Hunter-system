import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { homeFor, useAuth } from './auth/AuthContext.jsx';
import { Layout } from './components/Layout.jsx';
import { AmbientBackground } from './components/AmbientBackground.jsx';
import { LandingPage } from './pages/Landing.jsx';
import { Loading } from './components/ui.jsx';
import { LoginPage, RegisterPage } from './pages/Auth.jsx';
import { ExperiencePage } from './pages/Experience.jsx';
import { AssessmentPage, AssessmentResultPage } from './pages/Assessment.jsx';
import { DashboardPage } from './pages/Dashboard.jsx';
import { TopicsPage, TopicPage } from './pages/Topics.jsx';
import { LessonPage } from './pages/Lesson.jsx';
import { QuizPage } from './pages/Quiz.jsx';
import { HistoryPage } from './pages/History.jsx';
import { StorePage } from './pages/Store.jsx';
import { ProfilePage } from './pages/Profile.jsx';
import { NotFoundPage } from './pages/Misc.jsx';
import { AdminBoundary, AdminOverview, AdminStudents, AdminStudent, AdminEvents, AdminTopics, AdminTopic, AdminLesson, AdminQuestions, AdminQuestion, AdminBadges, AdminBadge, AdminAudit } from './pages/Admin.jsx';

/**
 * Route guard. `stage` = which onboarding states may see the page:
 *   'start' (not_started), 'assessment' (experience_chosen, plus the result after
 *   submission), 'app' (assessment_submitted). Admins always go to /admin.
 * A lost session redirects to /login and remembers where the student was.
 */
function Student({ stage, children }) {
  const { status, user } = useAuth();
  const location = useLocation();
  if (status === 'loading') return <Loading what="Checking your session" />;
  if (status !== 'authenticated') return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  if (user.role !== 'student') return <Navigate to="/admin" replace />;
  const allowed = {
    start: ['not_started'],
    assessment: ['experience_chosen'],
    result: ['experience_chosen', 'assessment_submitted'],   // right after submitting, before the cached state is synced
    app: ['assessment_submitted'],
  }[stage];
  if (!allowed.includes(user.onboarding_state)) return <Navigate to={homeFor(user)} replace />;
  return children;
}

function Public({ children }) {
  const { status, user } = useAuth();
  const location = useLocation();
  if (status === 'loading') return <Loading what="Checking your session" />;
  if (status === 'authenticated') {
    const from = location.state?.from;
    return <Navigate to={from && user.role === 'student' ? from : homeFor(user)} replace />;
  }
  return children;
}

function AdminOnly({ children }) {
  const { status, user } = useAuth();
  if (status === 'loading') return <Loading what="Checking your session" />;
  if (status !== 'authenticated') return <Navigate to="/login" replace />;
  if (user.role !== 'admin') return <Navigate to={homeFor(user)} replace />;
  return <AdminBoundary>{children}</AdminBoundary>;
}

/**
 * "/": logged-out visitors see the public landing page; signed-in users keep the
 * existing redirect to their server-owned home (student onboarding step or /admin).
 */
function Home() {
  const { status, user } = useAuth();
  if (status === 'loading') return <div className="app app-minimal"><AmbientBackground /><main id="main" className="main"><Loading what="Checking your session" /></main></div>;
  if (status !== 'authenticated') return <LandingPage />;
  return <Navigate to={homeFor(user)} replace />;
}

export default function App() {
  return (
    <Routes>
      <Route index element={<Home />} />
      <Route element={<Layout minimal />}>
        <Route path="login" element={<Public><LoginPage /></Public>} />
        <Route path="register" element={<Public><RegisterPage /></Public>} />
        <Route path="start" element={<Student stage="start"><ExperiencePage /></Student>} />
        <Route path="assessment" element={<Student stage="assessment"><AssessmentPage /></Student>} />
        <Route path="admin-pending" element={<Navigate to="/admin" replace />} />
      </Route>
      <Route element={<Layout />}>
        <Route path="admin" element={<AdminOnly><AdminOverview /></AdminOnly>} />
        <Route path="admin/students" element={<AdminOnly><AdminStudents /></AdminOnly>} />
        <Route path="admin/students/:id" element={<AdminOnly><AdminStudent /></AdminOnly>} />
        <Route path="admin/login-events" element={<AdminOnly><AdminEvents /></AdminOnly>} />
        <Route path="admin/topics" element={<AdminOnly><AdminTopics /></AdminOnly>} />
        <Route path="admin/topics/:id" element={<AdminOnly><AdminTopic /></AdminOnly>} />
        <Route path="admin/topics/:id/lesson" element={<AdminOnly><AdminLesson /></AdminOnly>} />
        <Route path="admin/questions" element={<AdminOnly><AdminQuestions /></AdminOnly>} />
        <Route path="admin/questions/:id" element={<AdminOnly><AdminQuestion /></AdminOnly>} />
        <Route path="admin/badges" element={<AdminOnly><AdminBadges /></AdminOnly>} />
        <Route path="admin/badges/:id" element={<AdminOnly><AdminBadge /></AdminOnly>} />
        <Route path="admin/audit" element={<AdminOnly><AdminAudit /></AdminOnly>} />
        <Route path="assessment/result" element={<Student stage="result"><AssessmentResultPage /></Student>} />
        <Route path="dashboard" element={<Student stage="app"><DashboardPage /></Student>} />
        <Route path="topics" element={<Student stage="app"><TopicsPage /></Student>} />
        <Route path="topics/:topicId" element={<Student stage="app"><TopicPage /></Student>} />
        <Route path="topics/:topicId/lesson" element={<Student stage="app"><LessonPage /></Student>} />
        <Route path="quiz/:attemptId" element={<Student stage="app"><QuizPage /></Student>} />
        <Route path="history" element={<Student stage="app"><HistoryPage /></Student>} />
        <Route path="store" element={<Student stage="app"><StorePage /></Student>} />
        <Route path="profile" element={<Student stage="app"><ProfilePage /></Student>} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}

import { lazy, Suspense } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './auth';
import { ThemeProvider } from './theme';
import { PageLoader, ToastProvider } from './components/ui';
import ProtectedRoute from './components/ProtectedRoute';
import Layout from './components/Layout';
import Home from './pages/Home';
import Browse from './pages/Browse';
import CourseDetail from './pages/CourseDetail';
import Login from './pages/Login';
import Register from './pages/Register';
import VerifyCertificate from './pages/VerifyCertificate';

// Every page behind a login is fetched the first time it is opened. Statically
// importing all thirty-five meant a student who only reads lessons also
// downloaded the admin analytics page and every instructor tool, in one 650 kB
// bundle, before the first frame. The six pages an unauthenticated visitor can
// actually reach stay in the entry chunk so the landing page still paints from
// the initial request.
const MyCourses = lazy(() => import('./pages/MyCourses'));
const StudentDashboard = lazy(() => import('./pages/StudentDashboard'));
const LearningInsights = lazy(() => import('./pages/LearningInsights'));
const LearningMap = lazy(() => import('./pages/LearningMap'));
const ChallengePage = lazy(() => import('./pages/Challenge'));
const Portfolio = lazy(() => import('./pages/Portfolio'));
const Learn = lazy(() => import('./pages/Learn'));
const QuizPage = lazy(() => import('./pages/Quiz'));
const QuizReview = lazy(() => import('./pages/QuizReview'));
const AssignmentPage = lazy(() => import('./pages/Assignment'));
const Grades = lazy(() => import('./pages/Grades'));
const Certificates = lazy(() => import('./pages/Certificates'));
const Notifications = lazy(() => import('./pages/Notifications'));
const Profile = lazy(() => import('./pages/Profile'));
const AgentInbox = lazy(() => import('./pages/support/AgentInbox'));
const InstructorDashboard = lazy(() => import('./pages/instructor/InstructorDashboard'));
const InstructorCourses = lazy(() => import('./pages/instructor/InstructorCourses'));
const InstructorCourseBuilder = lazy(() => import('./pages/instructor/InstructorCourseBuilder'));
const InstructorStudents = lazy(() => import('./pages/instructor/InstructorStudents'));
const InstructorSubmissions = lazy(() => import('./pages/instructor/InstructorSubmissions'));
const InstructorAnalytics = lazy(() => import('./pages/instructor/InstructorAnalytics'));
const InstructorRadar = lazy(() => import('./pages/instructor/InstructorRadar'));
const InstructorGradebook = lazy(() => import('./pages/instructor/InstructorGradebook'));
const AdminDashboard = lazy(() => import('./pages/admin/AdminDashboard'));
const AdminUsers = lazy(() => import('./pages/admin/AdminUsers'));
const AdminRoles = lazy(() => import('./pages/admin/AdminRoles'));
const AdminCategories = lazy(() => import('./pages/admin/AdminCategories'));
const AdminCourses = lazy(() => import('./pages/admin/AdminCourses'));
const AdminEnrollments = lazy(() => import('./pages/admin/AdminEnrollments'));
const AdminCertificates = lazy(() => import('./pages/admin/AdminCertificates'));

// The boundary sits inside the layout on purpose: an outer one would swap the
// whole document for a spinner and take the navigation with it, so a route change
// would feel like the app had reloaded.
const inLayout = (element) => (
    <Layout>
        <Suspense fallback={<PageLoader />}>{element}</Suspense>
    </Layout>
);

createRoot(document.getElementById('app')).render(
    <ToastProvider>
        <ThemeProvider>
            <AuthProvider>
                <BrowserRouter>
                <Routes>
                    <Route path="/" element={inLayout(<Home />)} />
                    <Route path="/browse" element={inLayout(<Browse />)} />
                    <Route path="/courses/:slug" element={inLayout(<CourseDetail />)} />
                    <Route path="/verify-certificate" element={inLayout(<VerifyCertificate />)} />
                    <Route path="/login" element={<Login />} />
                    <Route path="/register" element={<Register />} />

                    <Route
                        path="/my-courses"
                        element={<ProtectedRoute>{inLayout(<MyCourses />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/dashboard"
                        element={<ProtectedRoute roles={['student', 'instructor', 'admin']}>{inLayout(<StudentDashboard />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/learning-insights"
                        element={<ProtectedRoute roles={['student', 'instructor', 'admin']}>{inLayout(<LearningInsights />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/learning-map"
                        element={<ProtectedRoute roles={['student', 'instructor', 'admin']}>{inLayout(<LearningMap />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/challenge"
                        element={<ProtectedRoute roles={['student', 'instructor', 'admin']}>{inLayout(<ChallengePage />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/portfolio"
                        element={<ProtectedRoute roles={['student', 'instructor', 'admin']}>{inLayout(<Portfolio />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/learn/:slug/*"
                        element={<ProtectedRoute>{inLayout(<Learn />)}</ProtectedRoute>}
                    />
                    <Route path="/quiz/:id" element={<ProtectedRoute>{inLayout(<QuizPage />)}</ProtectedRoute>} />
                    <Route path="/quiz/attempts/:attemptId" element={<ProtectedRoute>{inLayout(<QuizReview />)}</ProtectedRoute>} />
                    <Route
                        path="/assignment/:id"
                        element={<ProtectedRoute>{inLayout(<AssignmentPage />)}</ProtectedRoute>}
                    />
                    <Route path="/grades" element={<ProtectedRoute>{inLayout(<Grades />)}</ProtectedRoute>} />
                    <Route
                        path="/certificates"
                        element={<ProtectedRoute>{inLayout(<Certificates />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/certificates/:id"
                        element={<ProtectedRoute>{inLayout(<Certificates />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/notifications"
                        element={<ProtectedRoute>{inLayout(<Notifications />)}</ProtectedRoute>}
                    />
                    <Route path="/profile" element={<ProtectedRoute>{inLayout(<Profile />)}</ProtectedRoute>} />
                    <Route
                        path="/support/inbox"
                        element={<ProtectedRoute roles={['customer_service', 'admin']}>{inLayout(<AgentInbox />)}</ProtectedRoute>}
                    />

                    <Route
                        path="/instructor/dashboard"
                        element={<ProtectedRoute roles={['instructor', 'admin']}>{inLayout(<InstructorDashboard />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/instructor/courses"
                        element={<ProtectedRoute roles={['instructor', 'admin']}>{inLayout(<InstructorCourses />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/instructor/courses/:slug"
                        element={<ProtectedRoute roles={['instructor', 'admin']}>{inLayout(<InstructorCourseBuilder />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/instructor/courses/:slug/students"
                        element={<ProtectedRoute roles={['instructor', 'admin']}>{inLayout(<InstructorStudents />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/instructor/courses/:slug/submissions"
                        element={<ProtectedRoute roles={['instructor', 'admin']}>{inLayout(<InstructorSubmissions />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/instructor/courses/:slug/analytics"
                        element={<ProtectedRoute roles={['instructor', 'admin']}>{inLayout(<InstructorAnalytics />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/instructor/courses/:slug/radar"
                        element={<ProtectedRoute roles={['instructor', 'admin']}>{inLayout(<InstructorRadar />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/instructor/courses/:slug/gradebook"
                        element={<ProtectedRoute roles={['instructor', 'admin']}>{inLayout(<InstructorGradebook />)}</ProtectedRoute>}
                    />

                    <Route
                        path="/admin/dashboard"
                        element={<ProtectedRoute roles={['admin']}>{inLayout(<AdminDashboard />)}</ProtectedRoute>}
                    />
                    <Route path="/admin/users" element={<ProtectedRoute roles={['admin']}>{inLayout(<AdminUsers />)}</ProtectedRoute>} />
                    <Route path="/admin/roles" element={<ProtectedRoute roles={['admin']}>{inLayout(<AdminRoles />)}</ProtectedRoute>} />
                    <Route
                        path="/admin/categories"
                        element={<ProtectedRoute roles={['admin']}>{inLayout(<AdminCategories />)}</ProtectedRoute>}
                    />
                    <Route path="/admin/courses" element={<ProtectedRoute roles={['admin']}>{inLayout(<AdminCourses />)}</ProtectedRoute>} />
                    <Route
                        path="/admin/enrollments"
                        element={<ProtectedRoute roles={['admin']}>{inLayout(<AdminEnrollments />)}</ProtectedRoute>}
                    />
                    <Route
                        path="/admin/certificates"
                        element={<ProtectedRoute roles={['admin']}>{inLayout(<AdminCertificates />)}</ProtectedRoute>}
                    />

                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </BrowserRouter>
        </AuthProvider>
    </ThemeProvider>
</ToastProvider>,
);
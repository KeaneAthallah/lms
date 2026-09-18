import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './auth';
import { ThemeProvider } from './theme';
import { ToastProvider } from './components/ui';
import ProtectedRoute from './components/ProtectedRoute';
import Layout from './components/Layout';
import Home from './pages/Home';
import Browse from './pages/Browse';
import CourseDetail from './pages/CourseDetail';
import Login from './pages/Login';
import Register from './pages/Register';
import MyCourses from './pages/MyCourses';
import StudentDashboard from './pages/StudentDashboard';
import LearningInsights from './pages/LearningInsights';
import LearningMap from './pages/LearningMap';
import ChallengePage from './pages/Challenge';
import Portfolio from './pages/Portfolio';
import Learn from './pages/Learn';
import QuizPage from './pages/Quiz';
import AssignmentPage from './pages/Assignment';
import Grades from './pages/Grades';
import Certificates from './pages/Certificates';
import Notifications from './pages/Notifications';
import Profile from './pages/Profile';
import VerifyCertificate from './pages/VerifyCertificate';
import AgentInbox from './pages/support/AgentInbox';
import InstructorDashboard from './pages/instructor/InstructorDashboard';
import InstructorCourses from './pages/instructor/InstructorCourses';
import InstructorCourseBuilder from './pages/instructor/InstructorCourseBuilder';
import InstructorStudents from './pages/instructor/InstructorStudents';
import InstructorSubmissions from './pages/instructor/InstructorSubmissions';
import InstructorAnalytics from './pages/instructor/InstructorAnalytics';
import InstructorRadar from './pages/instructor/InstructorRadar';
import AdminDashboard from './pages/admin/AdminDashboard';
import AdminUsers from './pages/admin/AdminUsers';
import AdminRoles from './pages/admin/AdminRoles';
import AdminCategories from './pages/admin/AdminCategories';
import AdminCourses from './pages/admin/AdminCourses';
import AdminEnrollments from './pages/admin/AdminEnrollments';
import AdminCertificates from './pages/admin/AdminCertificates';

const inLayout = (element) => <Layout>{element}</Layout>;

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
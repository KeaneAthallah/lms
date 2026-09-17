import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../auth';

export default function ProtectedRoute({ roles, children }) {
    const { user, isAuthenticated } = useAuth();
    const location = useLocation();

    if (!isAuthenticated) {
        return <Navigate to={`/login?next=${encodeURIComponent(location.pathname + location.search)}`} replace />;
    }

    if (roles && !roles.some((role) => user.roles?.includes(role))) {
        return <Navigate to="/" replace />;
    }

    return children;
}
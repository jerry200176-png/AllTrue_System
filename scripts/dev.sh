#!/bin/bash
cd "$(dirname "$0")"

echo ""
echo "========================================"
echo "  AllTrue - Dev Mode (Apache/PHP/MySQL)"
echo "========================================"
echo ""
echo "  Backend API:  http://localhost:8000"
echo "  Frontend:     http://localhost:5173"
echo ""

# Install frontend deps if needed
if [ ! -d "frontend/node_modules" ]; then
    echo "Installing frontend packages..."
    (cd frontend && npm install)
fi

# Start Laravel backend in background
(cd backend && php artisan serve --port=8000) &
BACKEND_PID=$!

# Wait for backend to be ready
sleep 2

# Start frontend (foreground)
cd frontend && npm run dev

# When frontend stops, kill backend
kill $BACKEND_PID 2>/dev/null
echo ""
echo "Frontend stopped."

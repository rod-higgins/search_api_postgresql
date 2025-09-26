#!/bin/bash

## Script to run Cypress tests from macOS against DDEV environment
## This script should be run from the project root on macOS

set -e

echo "Search API PostgreSQL - Cypress E2E Testing"
echo "==========================================="

# Check if DDEV is running
if ! ddev describe > /dev/null 2>&1; then
    echo "❌ DDEV is not running. Starting DDEV..."
    ddev start

    # Wait for services to be ready
    sleep 10
else
    echo "✅ DDEV environment is running"
fi

# Verify DDEV URLs are accessible
DDEV_URL=$(ddev describe | grep "https://" | head -n1 | awk '{print $1}' | sed 's/,$//')
echo "🌐 Testing DDEV URL: $DDEV_URL"

# Test if the site is accessible
if curl -s --fail "$DDEV_URL" > /dev/null; then
    echo "✅ DDEV site is accessible"
else
    echo "❌ DDEV site is not accessible. Please check DDEV configuration."
    exit 1
fi

# Install Cypress dependencies if needed
if [ ! -d "node_modules" ]; then
    echo "📦 Installing Node.js dependencies..."
    npm install
fi

# Check if Cypress binary exists
if [ ! -d "node_modules/.bin" ] || [ ! -f "node_modules/.bin/cypress" ]; then
    echo "📦 Installing Cypress..."
    npm install cypress --save-dev
fi

# Run Cypress tests
echo "🧪 Running Cypress E2E tests..."
echo "   Tests will run against: $DDEV_URL"
echo "   Screenshots will be saved to: cypress/screenshots/"
echo "   Videos will be saved to: cypress/videos/"

# Set environment variables
export CYPRESS_BASE_URL="$DDEV_URL"

# Run tests based on argument
if [ "$1" == "open" ]; then
    echo "🖥️  Opening Cypress Test Runner..."
    npx cypress open
elif [ "$1" == "headless" ] || [ -z "$1" ]; then
    echo "🤖 Running Cypress tests in headless mode..."
    npx cypress run
elif [ "$1" == "screenshots" ]; then
    echo "📸 Running screenshot tests specifically..."
    npx cypress run --spec "cypress/e2e/admin-routes-screenshots.cy.js"
else
    echo "📝 Running specific test: $1"
    npx cypress run --spec "$1"
fi

echo "✅ Cypress testing completed!"
echo ""
echo "📊 Test Results:"
echo "   Screenshots: cypress/screenshots/"
echo "   Videos: cypress/videos/"
echo "   Reports: cypress/reports/"
echo ""
echo "🔧 Available commands:"
echo "   ./scripts/run-cypress-tests.sh open      - Open Cypress Test Runner"
echo "   ./scripts/run-cypress-tests.sh headless  - Run all tests headless"
echo "   ./scripts/run-cypress-tests.sh screenshots - Run screenshot tests only"
echo "   ddev phpunit-tests                        - Run PHPUnit tests in DDEV"
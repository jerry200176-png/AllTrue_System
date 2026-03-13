#!/bin/bash
# Fix for: Cannot find module @rollup/rollup-linux-arm64-gnu
# Run this once on Linux ARM64, then ./start.sh should work.
cd "$(dirname "$0")"
echo "Cleaning frontend dependencies for ARM64 reinstall..."
rm -rf node_modules package-lock.json
npm install
echo "Done. Run ../../start.sh to build and start."

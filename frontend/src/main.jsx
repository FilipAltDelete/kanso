import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles.css';
import { App } from './app/App.jsx';
import { applyTheme, savedTheme } from './lib/theme.js';

// Before the first render, so a dark theme never flashes white on load.
applyTheme(savedTheme());

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
);

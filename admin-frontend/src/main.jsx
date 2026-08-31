import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { ADMIN_BASE } from './base';
import App from './App';
import './admin.css';

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter basename={ADMIN_BASE}>
      <App />
    </BrowserRouter>
  </React.StrictMode>
);

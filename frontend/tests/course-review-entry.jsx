import React from 'react';
import { createRoot } from 'react-dom/client';
import { Markdown } from '../src/lib/markdown.jsx';
window.showLesson = body => createRoot(document.getElementById('root')).render(<Markdown source={body}/>);

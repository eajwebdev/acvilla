import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Home } from 'lucide-react';
import Brand from '../Components/Brand';
import PageSign from '../Components/PageSign';
import '../../css/acvilla.css';

const copy: Record<number, [string, string]> = {
  403: ['Access denied', 'Your account doesn’t have permission to open this page. If you think it should, ask your administrator.'],
  404: ['Page not found', 'The page you’re looking for has moved, been removed, or never existed.'],
  429: ['Slow down a little', 'Too many requests in a short time. Wait a minute, then try again.'],
  500: ['Something went wrong', 'We hit an unexpected error. It has been logged. Please try again in a moment.'],
  503: ['Back shortly', 'We’re doing some quick maintenance. Please check back in a few minutes.'],
};

/** Rendered by the exception handler outside local and testing; it has no shared props, so it stands alone. */
export default function ErrorPage({ status }: { status: number }) {
  const [title, body] = copy[status] ?? copy[500];
  return <div className="ac-site ac-auth flex flex-col">
    <div className="ac-page-sky" aria-hidden="true"/>
    <Head title={title}/>
    <div className="ac-auth-bar"><Brand plank/></div>
    <main className="page-enter ac-auth-error">
      <PageSign eyebrow={`Error ${status}`} title={title}/>
      <div className="ac-card mt-4 p-6 text-center sm:p-8">
      <p className="mx-auto max-w-md text-lg leading-8 text-slate">{body}</p>
      <div className="mt-6 flex flex-wrap justify-center gap-3">
        <button type="button" onClick={() => window.history.length > 1 ? window.history.back() : window.location.assign('/')} className="btn btn-light"><ArrowLeft size={17}/> Go back</button>
        <Link href="/" className="btn btn-pink"><Home size={17}/> Home</Link>
      </div>
      </div>
    </main>
  </div>;
}

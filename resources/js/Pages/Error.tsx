import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Home } from 'lucide-react';
import Brand from '../Components/Brand';

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
  return <div className="relative isolate flex min-h-screen flex-col overflow-hidden bg-[var(--background)] px-5 py-6 sm:px-10">
    <Head title={title}/>
    <div className="absolute -right-32 -top-32 -z-10 size-[28rem] rounded-full bg-pink/15 blur-[100px]"/>
    <div className="absolute -bottom-40 -left-32 -z-10 size-[30rem] rounded-full bg-navy/10 blur-[100px]"/>
    <Brand compact/>
    <main className="page-enter mx-auto flex w-full max-w-xl flex-1 flex-col items-center justify-center py-16 text-center">
      <p className="font-mono text-sm font-semibold tracking-[.3em] text-pink">ERROR {status}</p>
      <h1 className="mt-4 font-serif text-5xl font-medium tracking-[-.03em] text-navy sm:text-6xl">{title}</h1>
      <p className="mt-5 max-w-md text-lg leading-8 text-slate">{body}</p>
      <div className="mt-9 flex flex-wrap justify-center gap-3">
        <button type="button" onClick={() => window.history.length > 1 ? window.history.back() : window.location.assign('/')} className="btn btn-light"><ArrowLeft size={17}/> Go back</button>
        <Link href="/" className="btn btn-pink"><Home size={17}/> Home</Link>
      </div>
    </main>
  </div>;
}

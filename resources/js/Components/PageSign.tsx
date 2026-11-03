import type { ReactNode } from 'react';
import { Rich } from './siteContent';

/** A page title on the home page's wooden sign. Live text, so it fits any page: *stars* mark the blue words. */
export default function PageSign({ eyebrow, title, children, as: Heading = 'h1' }: { eyebrow?: ReactNode; title: string; children?: ReactNode; as?: 'h1' | 'h2' }) {
  return <div className="ac-page-head">
    <div className="ac-plate">
      <div className="ac-plate-words">
        {eyebrow && <p className="ac-plate-eyebrow">{eyebrow}</p>}
        <Heading className="ac-plate-title"><Rich text={title}/></Heading>
      </div>
      <img src="/images/acvilla/ui/wheel.png" alt="" aria-hidden="true" className="ac-plate-wheel"/>
    </div>
    {children && <p className="ac-page-sub">{children}</p>}
  </div>;
}

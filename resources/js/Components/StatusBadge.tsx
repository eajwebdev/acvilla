import { Status } from './ops';

/** Kept for existing imports; renders the shared status pill. */
export default function StatusBadge({ status }: { status: string }) {
  return <Status value={status}/>;
}

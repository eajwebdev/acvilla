import { Head, Link } from '@inertiajs/react';
import { ShoppingBasket } from 'lucide-react';
import PosCatalog, { type CatalogItem } from '../../Components/PosCatalog';
import SettingsLayout from '../../Components/SettingsLayout';

export default function PosItems({ items }: { items: CatalogItem[] }) {
  return <SettingsLayout title="POS items" subtitle="What staff can sell at the counter: minibar, food, services and fees. Prices include tax."
    actions={<Link href="/admin/pos" className="btn btn-light"><ShoppingBasket size={15}/>Open register</Link>}>
    <Head title="POS items · Settings"/>
    <PosCatalog items={items}/>
  </SettingsLayout>;
}

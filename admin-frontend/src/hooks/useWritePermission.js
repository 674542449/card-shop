import { useOutletContext } from 'react-router-dom';
import { allows } from '../permissions';

export default function useWritePermission(area) {
  return allows(useOutletContext(), area, 'write');
}

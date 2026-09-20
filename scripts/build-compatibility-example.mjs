// Run with the bundled workspace Node runtime (see README.systems.md).
import fs from 'node:fs/promises';
import { Workbook } from '@oai/artifact-tool';

const workbook = Workbook.create();
const sheet = workbook.worksheets.add('Compatibilidad');
sheet.getRange('A1:E3').values = [
  ['nombre','estado','fps','dispositivo','fecha prueba'],
  ['Aventura de ejemplo (ficticio)','gameplay_confirmado',59.7,'Moto g15 (ejemplo)','2026-09-17'],
  ['Puzzle de ejemplo (ficticio)','arranque_confirmado',null,'Moto g15 (ejemplo)','2026-09-17'],
];
workbook.recalculate();
console.log(await workbook.inspect({kind:'region',sheetId:'Compatibilidad',range:'A1:E3',maxChars:2000,tableMaxRows:3,tableMaxCols:5}));
// CSV has no presentation layer. Serialize the authored cell values with RFC
// quoting and UTF-8 BOM for Excel, preserving null as an empty CSV field.
const quote = value => '"' + String(value ?? '').replaceAll('"','""') + '"';
const csv = '\uFEFF' + sheet.getRange('A1:E3').values.map(row => row.map(quote).join(',')).join('\r\n') + '\r\n';
const output = process.argv[2] || new URL('../public_html/modules/custom/nelkano_home/assets/compatibilidad-ejemplo.csv', import.meta.url);
await fs.writeFile(output, csv, 'utf8');
const checked = await Workbook.fromCSV(await fs.readFile(output, 'utf8'), {sheetName:'Verificacion'});
if (checked.worksheets.getItemAt(0).getRange('A1:E3').values[2][0] !== 'Puzzle de ejemplo (ficticio)') throw new Error('CSV round trip failed');
console.log('CSV verified: header + 2 fictitious records.');

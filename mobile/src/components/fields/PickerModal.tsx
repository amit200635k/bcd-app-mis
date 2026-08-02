import React from 'react';
import {Modal, Pressable, ScrollView, StyleSheet, Text, TouchableOpacity} from 'react-native';

export interface Option {
  label: string;
  value: string;
}

interface Props {
  visible: boolean;
  title: string;
  options: Option[];
  selected?: string;
  onSelect: (value: string) => void;
  onClose: () => void;
  disabled?: boolean;
}

export default function PickerModal({visible, title, options, selected, onSelect, onClose, disabled}: Props) {
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose}>
        <Pressable style={styles.sheet} onPress={() => undefined}>
          <Text style={styles.title}>{title}</Text>
          <ScrollView style={styles.list} keyboardShouldPersistTaps="handled">
            {options.map((opt) => {
              const isSelected = opt.value === selected;
              return (
                <TouchableOpacity
                  key={opt.value}
                  style={[styles.row, isSelected && styles.rowSelected]}
                  onPress={() => {
                    onSelect(opt.value);
                    onClose();
                  }}
                  disabled={disabled}>
                  <Text style={[styles.label, isSelected && styles.labelSelected]}>{opt.label}</Text>
                  {isSelected ? <Text style={styles.check}>✓</Text> : null}
                </TouchableOpacity>
              );
            })}
            {options.length === 0 ? <Text style={styles.empty}>No options available.</Text> : null}
          </ScrollView>
          <TouchableOpacity style={styles.cancel} onPress={onClose}>
            <Text style={styles.cancelText}>Cancel</Text>
          </TouchableOpacity>
        </Pressable>
      </Pressable>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: {flex: 1, backgroundColor: 'rgba(0,0,0,0.4)', justifyContent: 'flex-end'},
  sheet: {
    backgroundColor: '#fff',
    borderTopLeftRadius: 18,
    borderTopRightRadius: 18,
    paddingBottom: 24,
    maxHeight: '72%',
  },
  title: {fontSize: 16, fontWeight: '700', color: '#1f3a68', padding: 18, paddingBottom: 8},
  list: {paddingHorizontal: 12, maxHeight: 420},
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 13,
    paddingHorizontal: 12,
    borderRadius: 10,
  },
  rowSelected: {backgroundColor: '#eef3fb'},
  label: {fontSize: 15, color: '#1a2233'},
  labelSelected: {color: '#1f3a68', fontWeight: '600'},
  check: {color: '#1f3a68', fontWeight: '800'},
  empty: {color: '#8a94a6', textAlign: 'center', paddingVertical: 20},
  cancel: {alignItems: 'center', paddingVertical: 14, borderTopWidth: 1, borderTopColor: '#eef1f7', marginTop: 8},
  cancelText: {color: '#1f3a68', fontWeight: '700', fontSize: 15},
});
